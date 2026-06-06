<?php

namespace Laith343\FcmBlast\Contracts;

interface MessageBuilder
{
    /**
     * Build the FCM v1 "message" body for a single device token.
     *
     * The engine injects the `token` key and wraps the result in the
     * top-level `{"message": ...}` envelope, so implementations only
     * return the message contents (notification, data, android, apns, ...).
     *
     * $context is whatever the TokenSource attached to this token via
     * Laith343\FcmBlast\Support\OutboundToken (e.g. locale, user data) —
     * null when the source yields a plain token string.
     *
     * @param  mixed  $context  Per-token context, or null.
     * @return array<string,mixed>
     */
    public function build(string $token, mixed $context = null): array;
}
