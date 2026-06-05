<?php

namespace Laith343\FcmBlast\Engine;

use CurlHandle;

/**
 * Pool of reusable curl handles. Reusing handles keeps the underlying TCP
 * socket alive across requests, avoiding ephemeral-port exhaustion at high RPS.
 */
final class HandlePool
{
    /** @var array<int,CurlHandle> */
    private array $pool = [];

    public function acquire(): CurlHandle
    {
        return array_pop($this->pool) ?? curl_init();
    }

    public function release(CurlHandle $handle): void
    {
        $this->pool[] = $handle;
    }

    public function closeAll(): void
    {
        foreach ($this->pool as $handle) {
            curl_close($handle);
        }
        $this->pool = [];
    }
}
