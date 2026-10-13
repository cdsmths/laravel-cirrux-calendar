<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Facades;

use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Cirrux as CirruxManager;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Collection<int, Calendar> calendars()
 * @method static CalendarResource calendar(string $uuid)
 *
 * @mixin CirruxManager
 */
final class Cirrux extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CirruxManager::class;
    }
}
