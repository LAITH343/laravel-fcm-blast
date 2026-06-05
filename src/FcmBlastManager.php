<?php

namespace Laith343\FcmBlast;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Laith343\FcmBlast\Contracts\RunReporter;
use Laith343\FcmBlast\Dispatching\RunPlanner;
use Laith343\FcmBlast\Jobs\SendFcmBatch;
use Laith343\FcmBlast\Support\BlastStatus;

class FcmBlastManager
{
    private ?string $tokenSourceClass = null;

    private ?string $messageBuilderClass = null;

    private ?string $invalidTokenHandlerClass = null;

    private ?bool $validateOnly = null;

    public function __construct(
        private Config $config,
        private RunReporter $reporter,
        private RunPlanner $planner,
    ) {}

    public function tokensFrom(string $tokenSourceClass): self
    {
        $this->tokenSourceClass = $tokenSourceClass;

        return $this;
    }

    public function buildMessage(string $messageBuilderClass): self
    {
        $this->messageBuilderClass = $messageBuilderClass;

        return $this;
    }

    public function onInvalidToken(string $handlerClass): self
    {
        $this->invalidTokenHandlerClass = $handlerClass;

        return $this;
    }

    public function validateOnly(bool $validateOnly = true): self
    {
        $this->validateOnly = $validateOnly;

        return $this;
    }

    /**
     * Plan, purge, persist, and dispatch a blast. Returns the run id.
     */
    public function start(int $total, int $workers): int
    {
        $tokenSourceClass = $this->resolved('token_source', $this->tokenSourceClass);
        $messageBuilderClass = $this->resolved('message_builder', $this->messageBuilderClass);
        $invalidHandlerClass = $this->invalidTokenHandlerClass
            ?? $this->config->get('fcm-blast.invalid_token_handler');

        $available = app($tokenSourceClass)->count();
        $total = min($total, $available);
        if ($total <= 0) {
            throw new InvalidArgumentException('No tokens available to blast.');
        }

        $globalCap = (int) $this->config->get('fcm-blast.rate_cap_per_sec', 10000);
        $rateCap = max(1, (int) floor($globalCap / $workers));
        $concurrency = max(200, (int) ceil($rateCap * 0.6));
        $validateOnly = $this->validateOnly ?? (bool) $this->config->get('fcm-blast.validate_only', false);

        $queue = (string) $this->config->get('fcm-blast.queue', 'fcm-blast');
        $endpoint = $this->endpoint();

        $this->purgeQueue($queue);

        $runId = $this->reporter->createRun($total, $workers, $rateCap, $validateOnly);

        $slices = $this->planner->slices($total, $workers);
        $this->reset();

        foreach ($slices as $slice) {
            SendFcmBatch::dispatch(
                $runId,
                $endpoint,
                $slice['offset'],
                $slice['limit'],
                $rateCap,
                $concurrency,
                $validateOnly,
                $tokenSourceClass,
                $messageBuilderClass,
                $invalidHandlerClass,
                (int) $this->config->get('fcm-blast.max_retries', 5),
                $queue,
            );
        }

        return $runId;
    }

    public function status(int $runId): BlastStatus
    {
        return $this->reporter->status($runId);
    }

    private function reset(): void
    {
        $this->tokenSourceClass = null;
        $this->messageBuilderClass = null;
        $this->invalidTokenHandlerClass = null;
        $this->validateOnly = null;
    }

    private function endpoint(): string
    {
        $override = $this->config->get('fcm-blast.endpoint');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $projectId = $this->config->get('fcm-blast.project_id');
        if (! $projectId) {
            throw new InvalidArgumentException('fcm-blast.project_id is not configured.');
        }

        return "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    }

    private function resolved(string $configKey, ?string $fluent): string
    {
        $class = $fluent ?? $this->config->get("fcm-blast.{$configKey}");
        if (! is_string($class) || $class === '') {
            throw new InvalidArgumentException("No {$configKey} configured. Set it fluently or in config/fcm-blast.php.");
        }

        return $class;
    }

    private function purgeQueue(string $queue): void
    {
        $connection = $this->config->get('fcm-blast.connection', 'default');
        if ($this->config->get('queue.connections.'.$connection.'.driver') !== 'redis') {
            return;
        }

        $prefix = (string) $this->config->get('database.redis.options.prefix', '');
        $redisConnection = $this->config->get('queue.connections.'.$connection.'.connection', 'default');
        $conn = Redis::connection($redisConnection);

        foreach (["queues:{$queue}", "queues:{$queue}:notify", "queues:{$queue}:reserved", "queues:{$queue}:delayed"] as $key) {
            $conn->del($prefix.$key);
        }
    }
}
