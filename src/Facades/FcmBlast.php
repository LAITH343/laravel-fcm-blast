<?php

namespace Laith343\FcmBlast\Facades;

use Illuminate\Support\Facades\Facade;
use Laith343\FcmBlast\FcmBlastManager;

/**
 * @method static \Laith343\FcmBlast\FcmBlastManager tokensFrom(string $tokenSourceClass)
 * @method static \Laith343\FcmBlast\FcmBlastManager buildMessage(string $messageBuilderClass)
 * @method static \Laith343\FcmBlast\FcmBlastManager onInvalidToken(string $handlerClass)
 * @method static \Laith343\FcmBlast\FcmBlastManager validateOnly(bool $validateOnly = true)
 * @method static int start(int $total, int $workers)
 * @method static \Laith343\FcmBlast\Support\BlastStatus status(int $runId)
 *
 * @see FcmBlastManager
 */
class FcmBlast extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FcmBlastManager::class;
    }
}
