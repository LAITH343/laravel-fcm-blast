<?php

use Laith343\FcmBlast\Support\OutboundToken;

it('defaults context to null for a bare token', function () {
    $outbound = new OutboundToken('device-token');

    expect($outbound->token)->toBe('device-token')
        ->and($outbound->context)->toBeNull();
});

it('carries arbitrary context (array or object)', function () {
    $outbound = new OutboundToken('device-token', ['locale' => 'ar', 'name' => 'Laith']);

    expect($outbound->token)->toBe('device-token')
        ->and($outbound->context)->toBe(['locale' => 'ar', 'name' => 'Laith']);
});
