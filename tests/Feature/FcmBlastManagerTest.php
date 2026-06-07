<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laith343\FcmBlast\Contracts\MessageBuilder;
use Laith343\FcmBlast\Contracts\TokenSource;
use Laith343\FcmBlast\Facades\FcmBlast;
use Laith343\FcmBlast\Jobs\SendFcmBatch;
use Laith343\FcmBlast\Models\FcmBlastRun;

uses(RefreshDatabase::class);

class FakeTokenSource implements TokenSource
{
    public function count(): int
    {
        return 100;
    }

    public function stream(int $offset, int $limit): Generator
    {
        for ($i = 0; $i < $limit; $i++) {
            yield "token-{$offset}-{$i}";
        }
    }
}

class FakeMessageBuilder implements MessageBuilder
{
    public function build(string $token, mixed $context = null): array
    {
        return ['data' => ['t' => $token]];
    }
}

beforeEach(function () {
    config()->set('fcm-blast.token_source', FakeTokenSource::class);
    config()->set('fcm-blast.message_builder', FakeMessageBuilder::class);
    config()->set('fcm-blast.endpoint', 'http://localhost:8080/send');
    config()->set('fcm-blast.connection', 'sync'); // non-redis: skip purge
    config()->set('fcm-blast.rate_cap_per_sec', 10000);
});

it('clamps the total to available tokens and dispatches one job per worker', function () {
    Queue::fake();

    $runId = FcmBlast::start(total: 1_000_000, workers: 4);

    Queue::assertPushed(SendFcmBatch::class, 4);

    $run = FcmBlastRun::find($runId);
    expect($run->total)->toBe(100)                 // clamped to FakeTokenSource::count()
        ->and($run->workers)->toBe(4)
        ->and($run->rate_cap_per_sec)->toBe(2500); // floor(10000 / 4)
});

it('produces contiguous non-overlapping slices across the dispatched jobs', function () {
    Queue::fake();

    FcmBlast::start(total: 100, workers: 4);

    $offsets = [];
    Queue::assertPushed(SendFcmBatch::class, function (SendFcmBatch $job) use (&$offsets) {
        $offsets[$job->offset] = $job->limit;

        return true;
    });

    ksort($offsets);
    $cursor = 0;
    foreach ($offsets as $offset => $limit) {
        expect($offset)->toBe($cursor);
        $cursor += $limit;
    }
    expect($cursor)->toBe(100);
});

it('resolves validate_only from the fluent override over config', function () {
    Queue::fake();
    config()->set('fcm-blast.validate_only', false);

    $runId = FcmBlast::validateOnly()->start(total: 10, workers: 1);

    expect(FcmBlastRun::find($runId)->validate_only)->toBeTrue();
    Queue::assertPushed(SendFcmBatch::class, fn (SendFcmBatch $job) => $job->validateOnly === true);
});

it('resets fluent state between runs', function () {
    Queue::fake();
    config()->set('fcm-blast.validate_only', false);

    FcmBlast::validateOnly()->start(total: 10, workers: 1);
    $secondRunId = FcmBlast::start(total: 10, workers: 1);

    expect(FcmBlastRun::find($secondRunId)->validate_only)->toBeFalse();
});

it('carries per-run context into the dispatched jobs', function () {
    Queue::fake();

    FcmBlast::withContext(['campaign_id' => 42])->start(total: 10, workers: 2);

    Queue::assertPushed(SendFcmBatch::class, fn (SendFcmBatch $job) => $job->context === ['campaign_id' => 42]);
});

it('resets context between runs', function () {
    Queue::fake();

    FcmBlast::withContext(['campaign_id' => 42])->start(total: 10, workers: 1);
    FcmBlast::start(total: 10, workers: 1);

    $contexts = [];
    Queue::assertPushed(SendFcmBatch::class, function (SendFcmBatch $job) use (&$contexts) {
        $contexts[] = $job->context;

        return true;
    });

    expect($contexts)->toContain(['campaign_id' => 42])->toContain(null);
});
