<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Entry point behind the Cirrux facade.
 */
final readonly class Cirrux
{
    public function __construct(
        private CalendarList $calendarList,
        private Container $container,
    ) {}

    /**
     * The calendars of the logged in user, cached for `cirrux.cache.calendars_ttl` seconds.
     *
     * @return Collection<int, Calendar>
     */
    public function calendars(): Collection
    {
        return $this->calendarList->get();
    }

    /**
     * One calendar by uuid, for its events: `Cirrux::calendar($uuid)->events()->between($from, $to)->get()`.
     */
    public function calendar(string $uuid): CalendarResource
    {
        return new CalendarResource($uuid, $this->container);
    }
}
