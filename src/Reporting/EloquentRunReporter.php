<?php

namespace Laith343\FcmBlast\Reporting;

use Illuminate\Support\Facades\DB;
use Laith343\FcmBlast\Contracts\RunReporter;
use Laith343\FcmBlast\Events\FcmBlastCompleted;
use Laith343\FcmBlast\Models\FcmBlastRun;
use Laith343\FcmBlast\Support\BlastStatus;

class EloquentRunReporter implements RunReporter
{
    public function createRun(int $total, int $workers, int $rateCapPerSec, bool $validateOnly): int
    {
        return (int) FcmBlastRun::create([
            'total' => $total,
            'workers' => $workers,
            'rate_cap_per_sec' => $rateCapPerSec,
            'validate_only' => $validateOnly,
            'status' => 'pending',
        ])->id;
    }

    public function markRunning(int $runId): void
    {
        FcmBlastRun::where('id', $runId)
            ->whereNull('started_at')
            ->update(['started_at' => now(), 'status' => 'running']);
    }

    public function flush(int $runId, array $delta): void
    {
        DB::table('fcm_blast_runs')->where('id', $runId)->update([
            'sent' => DB::raw('sent + '.(int) $delta['sent']),
            'ok' => DB::raw('ok + '.(int) $delta['ok']),
            'failed' => DB::raw('failed + '.(int) $delta['failed']),
            'invalid' => DB::raw('invalid + '.(int) $delta['invalid']),
            'throttled' => DB::raw('throttled + '.(int) $delta['throttled']),
            'transport_retries' => DB::raw('transport_retries + '.(int) $delta['transport']),
            'latency_sum_ms' => DB::raw('latency_sum_ms + '.(int) $delta['latency_sum_ms']),
            'updated_at' => now(),
        ]);
    }

    public function finalize(int $runId, int $total): void
    {
        DB::transaction(function () use ($runId, $total) {
            $run = FcmBlastRun::where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->isFinished()) {
                return;
            }
            if ($run->terminalCount() >= $total) {
                $run->status = 'completed';
                $run->finished_at = now();
                $run->save();

                FcmBlastCompleted::dispatch($runId);
            }
        });
    }

    public function status(int $runId): BlastStatus
    {
        $run = FcmBlastRun::findOrFail($runId);

        return new BlastStatus(
            id: $run->id,
            status: $run->status,
            total: $run->total,
            sent: $run->sent,
            ok: $run->ok,
            failed: $run->failed,
            invalid: $run->invalid,
            throttled: $run->throttled,
            transportRetries: $run->transport_retries,
            workers: $run->workers,
            rateCapPerSec: $run->rate_cap_per_sec,
            validateOnly: $run->validate_only,
            progressPercent: round($run->progressPercent(), 1),
            rps: round($run->effectiveRps(), 1),
            avgLatencyMs: round($run->avgLatencyMs(), 1),
            elapsedSeconds: round($run->elapsedSeconds(), 1),
            finished: $run->isFinished(),
            stalled: $run->isStalled(),
        );
    }
}
