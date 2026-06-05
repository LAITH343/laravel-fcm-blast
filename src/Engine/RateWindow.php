<?php

namespace Laith343\FcmBlast\Engine;

/**
 * Sliding 1-second window rate limiter. O(1) amortized via a head pointer
 * that advances past expired timestamps instead of shifting the array.
 */
final class RateWindow
{
    /** @var array<int,float> */
    private array $window = [];

    private int $head = 0;

    public function __construct(private int $rate) {}

    public function allows(float $now): bool
    {
        $cutoff = $now - 1.0;
        while ($this->head < count($this->window) && $this->window[$this->head] < $cutoff) {
            $this->head++;
        }

        if ($this->head > 1000) {
            $this->window = array_slice($this->window, $this->head);
            $this->head = 0;
        }

        return (count($this->window) - $this->head) < $this->rate;
    }

    public function record(float $now): void
    {
        $this->window[] = $now;
    }
}
