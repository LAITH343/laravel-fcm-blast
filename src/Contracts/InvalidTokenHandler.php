<?php

namespace Laith343\FcmBlast\Contracts;

interface InvalidTokenHandler
{
    /**
     * Called when FCM rejects a token as permanently invalid
     * (HTTP 404 UNREGISTERED or 400). Use this to prune the token
     * from your own store.
     */
    public function __invoke(string $token): void;
}
