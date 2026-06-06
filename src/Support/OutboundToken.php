<?php

namespace Laith343\FcmBlast\Support;

/**
 * Optional wrapper a TokenSource may yield instead of a bare token string,
 * to attach per-token context (locale, user data, custom flags) that the
 * MessageBuilder receives. Yielding a plain string keeps context null.
 */
final readonly class OutboundToken
{
    public function __construct(
        public string $token,
        public mixed $context = null,
    ) {}
}
