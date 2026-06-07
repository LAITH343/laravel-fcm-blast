<?php

namespace Laith343\FcmBlast\Logging;

/**
 * Buffered, rotating NDJSON request logger.
 *
 * Records are held in memory and flushed to disk in bulk (one append per
 * flush tick, plus a hard buffer cap) so the hot send loop never pays a
 * per-request disk write. One date-stamped file per day; files older than
 * the retention window are pruned on demand.
 */
final class RequestLogger
{
    private const MAX_BUFFER = 2000;

    private const FILE_PREFIX = 'fcm-blast-requests-';

    /** @var list<string> */
    private array $buffer = [];

    public function __construct(
        private string $directory,
        private int $retentionDays,
    ) {}

    /**
     * Queue one request record. Flushes automatically once the in-memory
     * buffer reaches MAX_BUFFER to bound memory regardless of flush cadence.
     *
     * @param  array<string,mixed>  $entry
     */
    public function record(array $entry): void
    {
        $this->buffer[] = (string) json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (count($this->buffer) >= self::MAX_BUFFER) {
            $this->flush();
        }
    }

    /**
     * Append the buffered records to today's file in a single locked write.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $file = $this->directory.DIRECTORY_SEPARATOR.self::FILE_PREFIX.date('Y-m-d').'.log';
        file_put_contents($file, implode(PHP_EOL, $this->buffer).PHP_EOL, FILE_APPEND | LOCK_EX);

        $this->buffer = [];
    }

    /**
     * Delete log files older than the retention window. Disabled when
     * retentionDays <= 0.
     */
    public function prune(): void
    {
        if ($this->retentionDays <= 0 || ! is_dir($this->directory)) {
            return;
        }

        $cutoff = time() - ($this->retentionDays * 86400);

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.self::FILE_PREFIX.'*.log') ?: [] as $path) {
            if (filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }
}
