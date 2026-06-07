<?php

use Laith343\FcmBlast\Dispatching\RunPlanner;

it('splits a total into contiguous non-overlapping slices covering everything', function () {
    $slices = (new RunPlanner)->slices(1000, 3);

    expect($slices)->toHaveCount(3);
    expect(array_sum(array_column($slices, 'limit')))->toBe(1000);

    $cursor = 0;
    foreach ($slices as $slice) {
        expect($slice['offset'])->toBe($cursor);
        $cursor += $slice['limit'];
    }
});

it('stops allocating workers once the total is exhausted', function () {
    $slices = (new RunPlanner)->slices(5, 8);

    expect(array_sum(array_column($slices, 'limit')))->toBe(5);
    expect(count($slices))->toBeLessThanOrEqual(5);
});

it('returns no slices for non-positive input', function () {
    expect((new RunPlanner)->slices(0, 4))->toBe([]);
    expect((new RunPlanner)->slices(100, 0))->toBe([]);
});
