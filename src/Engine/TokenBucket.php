<?php

namespace Laith343\FcmBlast\Engine;

/**
 * Paced rate limiter. Tokens refill continuously at `ratePerSecond`, so
 * dispatches are spread evenly across each second instead of bursting at the
 * window edge. `capacity` bounds the maximum instantaneous burst — keeping it
 * small (well below the in-flight concurrency) is what stops sub-second spikes
 * from tripping FCM's token-bucket quota, independent of connection count.
 */
final class TokenBucket
{
    private float $tokens;

    private float $lastRefill;

    public function __construct(
        private float $ratePerSecond,
        private float $capacity,
    ) {
        $this->tokens = $capacity;
        $this->lastRefill = microtime(true);
    }

    /**
     * Consume one token if available. Returns false when the caller must wait.
     */
    public function tryConsume(float $now): bool
    {
        $elapsed = $now - $this->lastRefill;
        if ($elapsed > 0) {
            $this->tokens = min($this->capacity, $this->tokens + $elapsed * $this->ratePerSecond);
            $this->lastRefill = $now;
        }

        if ($this->tokens >= 1.0) {
            $this->tokens -= 1.0;

            return true;
        }

        return false;
    }
}
