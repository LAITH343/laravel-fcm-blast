<?php

use Laith343\FcmBlast\Logging\RequestLogger;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fcm-blast-test-'.uniqid();
});

afterEach(function () {
    foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

it('buffers records and writes one NDJSON line each on flush', function () {
    $logger = new RequestLogger($this->dir, 7);

    $logger->record(['token' => 'a', 'http_code' => 200]);
    $logger->record(['token' => 'b', 'http_code' => 404]);

    expect(glob($this->dir.DIRECTORY_SEPARATOR.'*.log') ?: [])->toBeEmpty();

    $logger->flush();

    $file = $this->dir.DIRECTORY_SEPARATOR.'fcm-blast-requests-'.date('Y-m-d').'.log';
    $lines = array_values(array_filter(explode(PHP_EOL, file_get_contents($file))));

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true))->toMatchArray(['token' => 'a', 'http_code' => 200])
        ->and(json_decode($lines[1], true))->toMatchArray(['token' => 'b', 'http_code' => 404]);
});

it('appends across flushes without truncating', function () {
    $logger = new RequestLogger($this->dir, 7);

    $logger->record(['n' => 1]);
    $logger->flush();
    $logger->record(['n' => 2]);
    $logger->flush();

    $file = $this->dir.DIRECTORY_SEPARATOR.'fcm-blast-requests-'.date('Y-m-d').'.log';
    $lines = array_values(array_filter(explode(PHP_EOL, file_get_contents($file))));

    expect($lines)->toHaveCount(2);
});

it('prunes log files older than the retention window', function () {
    @mkdir($this->dir, 0775, true);
    $old = $this->dir.DIRECTORY_SEPARATOR.'fcm-blast-requests-2000-01-01.log';
    $fresh = $this->dir.DIRECTORY_SEPARATOR.'fcm-blast-requests-'.date('Y-m-d').'.log';
    file_put_contents($old, "{}\n");
    file_put_contents($fresh, "{}\n");
    touch($old, time() - (10 * 86400));

    (new RequestLogger($this->dir, 7))->prune();

    expect(file_exists($old))->toBeFalse()
        ->and(file_exists($fresh))->toBeTrue();
});

it('does not prune when retention is zero', function () {
    @mkdir($this->dir, 0775, true);
    $old = $this->dir.DIRECTORY_SEPARATOR.'fcm-blast-requests-2000-01-01.log';
    file_put_contents($old, "{}\n");
    touch($old, time() - (10 * 86400));

    (new RequestLogger($this->dir, 0))->prune();

    expect(file_exists($old))->toBeTrue();
});
