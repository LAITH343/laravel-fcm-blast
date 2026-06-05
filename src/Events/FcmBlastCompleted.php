<?php

namespace Laith343\FcmBlast\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FcmBlastCompleted
{
    use Dispatchable;

    public function __construct(public int $runId) {}
}
