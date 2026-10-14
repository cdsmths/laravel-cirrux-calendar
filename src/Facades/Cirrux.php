<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Facades;

use Carbon\CarbonInterface;
use Codesmiths\CirruxCalendar\Availability\Availability;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Cirrux as CirruxManager;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Collection<int, Calendar> calendars()
 * @method static CalendarResource calendar(string $uuid)
 * @method static Availability availability(string $eventType)
 * @method static Booking book(string $eventType, CarbonInterface $start, Attendee $attendee, ?string $notes = null, ?string $title = null, ?string $url = null, ?string $location = null)
 * @method static void cancel(Booking|string $booking)
 * @method static void refresh(?string $calendarUuid = null)
 * @method static void forget(?string $calendarUuid = null)
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
