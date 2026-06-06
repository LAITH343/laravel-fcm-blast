<?php

namespace Laith343\FcmBlast\Engine;

use Closure;
use Generator;
use Laith343\FcmBlast\Contracts\MessageBuilder;

final class EngineRunConfig
{
    /**
     * @param  Generator<int,string>  $tokens  Stream of device tokens for this worker's slice.
     * @param  (Closure(string):void)|null  $onInvalidToken  Called with each permanently invalid token.
     */
    public function __construct(
        public int $runId,
        public string $endpoint,
        public int $count,
        public int $rateCapPerSec,
        public int $concurrency,
        public bool $validateOnly,
        public Generator $tokens,
        public MessageBuilder $messageBuilder,
        public int $maxRetries = 5,
        public ?Closure $onInvalidToken = null,
        public int $httpVersion = CURL_HTTP_VERSION_2TLS,
        public ?int $maxHostConnections = null,
        public int $maxConcurrentStreams = 100,
        public int $rateBurst = 0,
    ) {}
}
