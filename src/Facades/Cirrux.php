<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Facades;

use Codesmiths\CirruxCalendar\Cirrux as CirruxManager;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CirruxManager
 */
final class Cirrux extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CirruxManager::class;
    }
}
