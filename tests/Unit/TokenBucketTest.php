<?php

use Laith343\FcmBlast\Engine\TokenBucket;

it('allows a burst up to capacity, then blocks until refill', function () {
    $now = microtime(true);
    $bucket = new TokenBucket(ratePerSecond: 100, capacity: 5);

    for ($i = 0; $i < 5; $i++) {
        expect($bucket->tryConsume($now))->toBeTrue();
    }
    expect($bucket->tryConsume($now))->toBeFalse();

    // 0.05s later, 0.05 * 100 = 5 tokens have refilled.
    expect($bucket->tryConsume($now + 0.05))->toBeTrue();
});

it('caps refill at capacity so the burst never grows unbounded', function () {
    $now = microtime(true);
    $bucket = new TokenBucket(100, 5);

    for ($i = 0; $i < 5; $i++) {
        $bucket->tryConsume($now);
    }

    // Even after a long idle gap, only `capacity` tokens are available.
    $granted = 0;
    for ($i = 0; $i < 100; $i++) {
        if ($bucket->tryConsume($now + 10.0)) {
            $granted++;
        }
    }
    expect($granted)->toBe(5);
});

it('sustains the configured rate over a second', function () {
    $now = microtime(true);
    $bucket = new TokenBucket(1000, 50);

    while ($bucket->tryConsume($now)) {
        // drain the initial burst
    }

    $granted = 0;
    for ($t = 0.0; $t <= 1.0; $t += 0.001) {
        while ($bucket->tryConsume($now + $t)) {
            $granted++;
        }
    }

    expect($granted)->toBeGreaterThanOrEqual(950)->toBeLessThanOrEqual(1100);
});
