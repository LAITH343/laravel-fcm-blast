<?php

namespace Laith343\FcmBlast\Contracts;

use Generator;
use Laith343\FcmBlast\Support\OutboundToken;

interface TokenSource
{
    /**
     * Total number of device tokens available to blast.
     */
    public function count(): int;

    /**
     * Stream device tokens for a contiguous slice of the source.
     *
     * Yield a plain token string, or a Laith343\FcmBlast\Support\OutboundToken
     * to attach per-token context (locale, user data) that reaches the
     * MessageBuilder. Mixing both is fine.
     *
     * @return Generator<int,string|OutboundToken>
     */
    public function stream(int $offset, int $limit): Generator;
}
