<?php

namespace Laith343\FcmBlast\Engine;

use CurlHandle;
use Laith343\FcmBlast\Auth\TokenProvider;
use Laith343\FcmBlast\Contracts\RunReporter;
use Laith343\FcmBlast\Support\Outcome;

/**
 * Drives a reused curl_multi handle pool to push FCM v1 sends at the
 * configured per-second cap, classifying responses and atomically
 * flushing counter deltas to the reporter.
 */
class BlastEngine
{
    private const FLUSH_INTERVAL = 0.5;

    private const SELECT_TIMEOUT = 0.005;

    private const MAX_BACKOFF = 5.0;

    public function __construct(
        private TokenProvider $tokenProvider,
        private RunReporter $reporter,
    ) {}

    public function run(EngineRunConfig $config): void
    {
        $this->reporter->markRunning($config->runId);

        $bearer = $this->tokenProvider->token();
        $tokenCheckedAt = microtime(true);

        $hostConnections = $config->maxHostConnections ?? max(200, (int) ceil($config->rateCapPerSec * 0.3));
        $mh = curl_multi_init();
        curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $hostConnections);
        curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, $hostConnections);
        curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);

        $pool = new HandlePool;
        $rate = new RateWindow($config->rateCapPerSec);
        $tokens = $config->tokens;

        /** @var list<array{token:string,attempts:int,at:float}> $retry */
        $retry = [];
        /** @var array<int,CurlHandle> $inFlight */
        $inFlight = [];
        /** @var array<int,array{token:string,start:float,attempts:int}> $meta */
        $meta = [];

        $consumed = 0;
        $delta = $this->emptyDelta();
        $lastFlush = microtime(true);

        while (true) {
            $now = microtime(true);

            if ($now - $tokenCheckedAt >= self::FLUSH_INTERVAL) {
                $bearer = $this->tokenProvider->token();
                $tokenCheckedAt = $now;
            }

            $generatorDone = $consumed >= $config->count || ! $tokens->valid();
            if ($generatorDone && $inFlight === [] && $retry === []) {
                break;
            }

            $dispatchedThisTick = 0;
            while (count($inFlight) < $config->concurrency && $rate->allows($now)) {
                $item = $this->nextItem($retry, $tokens, $consumed, $config->count, $now);
                if ($item === null) {
                    break;
                }

                $ch = $pool->acquire();
                $this->configureHandle($ch, $item['token'], $bearer, $config);
                curl_multi_add_handle($mh, $ch);

                $id = spl_object_id($ch);
                $inFlight[$id] = $ch;
                $meta[$id] = ['token' => $item['token'], 'start' => $now, 'attempts' => $item['attempts']];
                $rate->record($now);
                $dispatchedThisTick++;
                $now = microtime(true);
            }

            curl_multi_exec($mh, $active);
            curl_multi_select($mh, self::SELECT_TIMEOUT);

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                $entry = $meta[$id];
                $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

                $delta['latency_sum_ms'] += (int) ((microtime(true) - $entry['start']) * 1000);
                $delta['sent']++;

                $outcome = $this->classify($info['result'], $code);
                $this->applyOutcome($outcome, $entry, $config, $retry, $delta);

                curl_multi_remove_handle($mh, $ch);
                unset($inFlight[$id], $meta[$id]);
                $pool->release($ch);
            }

            if (microtime(true) - $lastFlush >= self::FLUSH_INTERVAL) {
                $this->flush($config->runId, $delta);
                $lastFlush = microtime(true);
            }

            // Nothing to do but wait on a backoff timer: yield the CPU briefly.
            if ($dispatchedThisTick === 0 && $inFlight === [] && $retry !== []) {
                usleep(1000);
            }
        }

        $this->flush($config->runId, $delta);
        $pool->closeAll();
        curl_multi_close($mh);

        $this->reporter->finalize($config->runId, $config->count);
    }

    /**
     * @param  list<array{token:string,attempts:int,at:float}>  $retry
     * @param  \Generator<int,string>  $tokens
     * @return array{token:string,attempts:int,at:float}|null
     */
    private function nextItem(array &$retry, \Generator $tokens, int &$consumed, int $count, float $now): ?array
    {
        foreach ($retry as $i => $item) {
            if ($item['at'] <= $now) {
                unset($retry[$i]);

                return $item;
            }
        }

        if ($consumed < $count && $tokens->valid()) {
            $token = $tokens->current();
            $tokens->next();
            $consumed++;

            return ['token' => $token, 'attempts' => 0, 'at' => 0.0];
        }

        return null;
    }

    /**
     * @param  array{token:string,start:float,attempts:int}  $entry
     * @param  list<array{token:string,attempts:int,at:float}>  $retry
     * @param  array<string,int>  $delta
     */
    private function applyOutcome(Outcome $outcome, array $entry, EngineRunConfig $config, array &$retry, array &$delta): void
    {
        if ($outcome === Outcome::Throttled && $config->maxRetries > $entry['attempts'] + 1) {
            $delta['throttled']++;
            $backoff = min(self::MAX_BACKOFF, (2 ** $entry['attempts']) * 0.25);
            $retry[] = [
                'token' => $entry['token'],
                'attempts' => $entry['attempts'] + 1,
                'at' => microtime(true) + $backoff,
            ];

            return;
        }

        switch ($outcome) {
            case Outcome::Ok:
                $delta['ok']++;
                break;
            case Outcome::Invalid:
                $delta['invalid']++;
                if ($config->onInvalidToken !== null) {
                    ($config->onInvalidToken)($entry['token']);
                }
                break;
            default:
                $delta['failed']++;
        }
    }

    private function classify(int $result, int $code): Outcome
    {
        if ($result === CURLE_OK && $code >= 200 && $code < 300) {
            return Outcome::Ok;
        }
        if ($code === 404 || $code === 400) {
            return Outcome::Invalid;
        }
        if ($code === 429 || $code === 503) {
            return Outcome::Throttled;
        }

        return Outcome::Failed;
    }

    private function configureHandle(CurlHandle $ch, string $token, string $bearer, EngineRunConfig $config): void
    {
        $body = ['message' => $config->messageBuilder->build($token)];
        $body['message']['token'] = $token;
        if ($config->validateOnly) {
            $body['validate_only'] = true;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $config->endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$bearer,
                'Expect:',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FORBID_REUSE => 0,
            CURLOPT_FRESH_CONNECT => 0,
            CURLOPT_HTTP_VERSION => $config->httpVersion,
        ]);
    }

    /**
     * @param  array<string,int>  $delta
     */
    private function flush(int $runId, array &$delta): void
    {
        if ($delta['sent'] === 0 && $delta['ok'] === 0 && $delta['failed'] === 0 && $delta['invalid'] === 0) {
            return;
        }

        $this->reporter->flush($runId, $delta);
        $delta = $this->emptyDelta();
    }

    /**
     * @return array{sent:int,ok:int,failed:int,invalid:int,throttled:int,latency_sum_ms:int}
     */
    private function emptyDelta(): array
    {
        return ['sent' => 0, 'ok' => 0, 'failed' => 0, 'invalid' => 0, 'throttled' => 0, 'latency_sum_ms' => 0];
    }
}
