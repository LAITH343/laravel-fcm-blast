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
     * @return array<string,mixed>
     */
    public function build(string $token): array;
}
