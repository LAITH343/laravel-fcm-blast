<?php

namespace Laith343\FcmBlast\Auth;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

class TokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string,mixed>|null  $serviceAccount  Decoded service-account JSON, or null for fake-token test mode.
     */
    public function __construct(
        private Cache $cache,
        private ?array $serviceAccount,
        private string $cacheKey,
        private int $refreshBufferSeconds = 600,
    ) {}

    /**
     * Return a valid OAuth2 bearer token, refreshing it when within the buffer window.
     * Intended to be called on the worker's flush cadence, not per request.
     */
    public function token(): string
    {
        if ($this->serviceAccount === null) {
            return 'fake';
        }

        $cached = $this->cache->get($this->cacheKey);
        if ($this->isFresh($cached)) {
            return $cached['access_token'];
        }

        return $this->refresh();
    }

    private function refresh(): string
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            $lock = $store->lock($this->cacheKey.':refresh', 10);

            return $lock->block(5, fn (): string => $this->fetchAndCache());
        }

        return $this->fetchAndCache();
    }

    private function fetchAndCache(): string
    {
        // Re-check inside the lock: another worker may have refreshed already.
        $cached = $this->cache->get($this->cacheKey);
        if ($this->isFresh($cached)) {
            return $cached['access_token'];
        }

        $credentials = new ServiceAccountCredentials(self::SCOPE, $this->serviceAccount);
        $fetched = $credentials->fetchAuthToken();

        $accessToken = (string) ($fetched['access_token'] ?? '');
        $expiresIn = (int) ($fetched['expires_in'] ?? 3600);

        $this->cache->put($this->cacheKey, [
            'access_token' => $accessToken,
            'expires_at' => time() + $expiresIn,
        ], $expiresIn);

        return $accessToken;
    }

    /**
     * @param  mixed  $cached
     */
    private function isFresh($cached): bool
    {
        return is_array($cached)
            && isset($cached['access_token'], $cached['expires_at'])
            && ($cached['expires_at'] - time()) > $this->refreshBufferSeconds;
    }
}
