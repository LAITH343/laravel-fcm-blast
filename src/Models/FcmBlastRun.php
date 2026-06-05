<?php

namespace Laith343\FcmBlast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $total
 * @property int $workers
 * @property int $rate_cap_per_sec
 * @property int $sent
 * @property int $ok
 * @property int $failed
 * @property int $invalid
 * @property int $throttled
 * @property int $latency_sum_ms
 * @property bool $validate_only
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class FcmBlastRun extends Model
{
    protected $table = 'fcm_blast_runs';

    protected $guarded = [];

    protected $casts = [
        'validate_only' => 'bool',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function terminalCount(): int
    {
        return $this->ok + $this->failed + $this->invalid;
    }

    public function progressPercent(): float
    {
        if ($this->total === 0) {
            return 0.0;
        }

        return min(100.0, ($this->terminalCount() / $this->total) * 100);
    }

    public function effectiveRps(): float
    {
        $start = $this->started_at?->getTimestamp();
        if (! $start) {
            return 0.0;
        }
        $end = $this->finished_at?->getTimestamp() ?? time();

        return $this->terminalCount() / max(1, $end - $start);
    }

    public function elapsedSeconds(): float
    {
        $start = $this->started_at?->getTimestamp();
        if (! $start) {
            return 0.0;
        }
        $end = $this->finished_at?->getTimestamp() ?? time();

        return max(0, $end - $start);
    }

    public function avgLatencyMs(): float
    {
        $done = $this->ok + $this->failed;
        if ($done === 0) {
            return 0.0;
        }

        return $this->latency_sum_ms / $done;
    }
}
