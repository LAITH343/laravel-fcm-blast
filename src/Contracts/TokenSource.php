<?php

namespace Laith343\FcmBlast\Contracts;

use Generator;

interface TokenSource
{
    /**
     * Total number of device tokens available to blast.
     */
    public function count(): int;

    /**
     * Stream device tokens for a contiguous slice of the source.
     *
     * @return Generator<int,string>
     */
    public function stream(int $offset, int $limit): Generator;
}
