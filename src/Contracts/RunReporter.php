<?php

namespace Laith343\FcmBlast\Contracts;

use Laith343\FcmBlast\Support\BlastStatus;

interface RunReporter
{
    /**
     * Persist a new run and return its id.
     */
    public function createRun(int $total, int $workers, int $rateCapPerSec, bool $validateOnly): int;

    /**
     * Mark a run as running on first worker pickup (idempotent).
     */
    public function markRunning(int $runId): void;

    /**
     * Atomically apply a batch of counter deltas accumulated by a worker.
     *
     * @param  array{sent:int,ok:int,failed:int,invalid:int,throttled:int,transport:int,latency_sum_ms:int}  $delta
     */
    public function flush(int $runId, array $delta): void;

    /**
     * Flip the run to completed once every token has reached a terminal outcome.
     */
    public function finalize(int $runId, int $total): void;

    public function status(int $runId): BlastStatus;
}
