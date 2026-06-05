<?php

namespace Laith343\FcmBlast\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laith343\FcmBlast\Contracts\InvalidTokenHandler;
use Laith343\FcmBlast\Contracts\MessageBuilder;
use Laith343\FcmBlast\Contracts\TokenSource;
use Laith343\FcmBlast\Engine\BlastEngine;
use Laith343\FcmBlast\Engine\EngineRunConfig;

class SendFcmBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  class-string<TokenSource>  $tokenSourceClass
     * @param  class-string<MessageBuilder>  $messageBuilderClass
     * @param  class-string<InvalidTokenHandler>|null  $invalidTokenHandlerClass
     */
    public function __construct(
        public int $runId,
        public string $endpoint,
        public int $offset,
        public int $limit,
        public int $rateCapPerSec,
        public int $concurrency,
        public bool $validateOnly,
        public string $tokenSourceClass,
        public string $messageBuilderClass,
        public ?string $invalidTokenHandlerClass,
        public int $maxRetries,
        public int $httpVersion,
        public ?int $maxHostConnections,
        public string $queueName,
    ) {
        $this->onQueue($queueName);
    }

    public function handle(BlastEngine $engine): void
    {
        /** @var TokenSource $source */
        $source = app($this->tokenSourceClass);
        /** @var MessageBuilder $builder */
        $builder = app($this->messageBuilderClass);

        $onInvalid = null;
        if ($this->invalidTokenHandlerClass !== null) {
            /** @var InvalidTokenHandler $handler */
            $handler = app($this->invalidTokenHandlerClass);
            $onInvalid = static fn (string $token) => $handler($token);
        }

        $engine->run(new EngineRunConfig(
            runId: $this->runId,
            endpoint: $this->endpoint,
            count: $this->limit,
            rateCapPerSec: $this->rateCapPerSec,
            concurrency: $this->concurrency,
            validateOnly: $this->validateOnly,
            tokens: $source->stream($this->offset, $this->limit),
            messageBuilder: $builder,
            maxRetries: $this->maxRetries,
            onInvalidToken: $onInvalid,
            httpVersion: $this->httpVersion,
            maxHostConnections: $this->maxHostConnections,
        ));
    }
}
