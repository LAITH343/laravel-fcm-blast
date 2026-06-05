<?php

namespace Laith343\FcmBlast\Support;

final readonly class BlastStatus
{
    public function __construct(
        public int $id,
        public string $status,
        public int $total,
        public int $sent,
        public int $ok,
        public int $failed,
        public int $invalid,
        public int $throttled,
        public int $transportRetries,
        public int $workers,
        public int $rateCapPerSec,
        public bool $validateOnly,
        public float $progressPercent,
        public float $rps,
        public float $avgLatencyMs,
        public float $elapsedSeconds,
        public bool $finished,
        public bool $stalled,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total' => $this->total,
            'sent' => $this->sent,
            'ok' => $this->ok,
            'failed' => $this->failed,
            'invalid' => $this->invalid,
            'throttled' => $this->throttled,
            'transport_retries' => $this->transportRetries,
            'workers' => $this->workers,
            'rate_cap_per_sec' => $this->rateCapPerSec,
            'validate_only' => $this->validateOnly,
            'progress_percent' => $this->progressPercent,
            'rps' => $this->rps,
            'avg_latency_ms' => $this->avgLatencyMs,
            'elapsed_seconds' => $this->elapsedSeconds,
            'finished' => $this->finished,
            'stalled' => $this->stalled,
        ];
    }
}
