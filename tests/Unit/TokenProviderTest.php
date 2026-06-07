<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Laith343\FcmBlast\Auth\TokenProvider;

it('returns a fake token when no credentials are configured', function () {
    $provider = new TokenProvider(
        cache: new Repository(new ArrayStore),
        serviceAccount: null,
        cacheKey: 'fcm-blast:test',
    );

    expect($provider->token())->toBe('fake');
});

it('serves a cached token while it is still outside the refresh buffer', function () {
    $store = new Repository(new ArrayStore);
    $store->put('fcm-blast:test', [
        'access_token' => 'cached-token',
        'expires_at' => time() + 3600,
    ], 3600);

    $provider = new TokenProvider(
        cache: $store,
        serviceAccount: ['type' => 'service_account'],
        cacheKey: 'fcm-blast:test',
        refreshBufferSeconds: 600,
    );

    expect($provider->token())->toBe('cached-token');
});
