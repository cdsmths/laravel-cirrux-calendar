<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Closure;
use Codesmiths\CirruxCalendar\Actions\Availability\ReportStaleCalendar;
use Codesmiths\CirruxCalendar\Actions\Cache\ForgetCalendar;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Availability\Availability;
use Codesmiths\CirruxCalendar\Availability\SlotCalculator;
use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Calendars\ConfiguredCalendars;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
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
        private ConfiguredCalendars $configuredCalendars,
        private RefreshCalendar $refreshCalendar,
        private ForgetCalendar $forgetCalendar,
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

    /**
     * The free slots of one configured event type:
     * `Cirrux::availability('demo')->between($from, $to)->inTimezone($tz)->slots()`.
     *
     * @throws InvalidConfigurationException for an unknown event type, an invalid one or one without a calendar
     */
    public function availability(string $eventType): Availability
    {
        $key = 'cirrux.event_types.'.$eventType;
        $config = config($key);

        if (! is_array($config)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        $type = EventType::fromConfig($eventType, $config);

        if ($type->calendar === null || $type->calendar === '') {
            throw InvalidConfigurationException::forKey($key.'.calendar', 'must be the uuid of a calendar');
        }

        return new Availability(
            $type,
            $this->calendar($type->calendar),
            $this->container->make(SlotCalculator::class),
            $this->container->make(ReportStaleCalendar::class),
        );
    }

    /**
     * Fetches the coming weeks from Cirrux into the cache: one calendar, or every configured calendar.
     *
     * Every calendar is tried. When some fail, the first failure is thrown afterwards; the
     * failure events were already dispatched. Without calendars in the config this does nothing.
     *
     * @throws CirruxException
     */
    public function refresh(?string $calendarUuid = null): void
    {
        $this->each($calendarUuid, $this->refreshCalendar->handle(...));
    }

    /**
     * Empties the cache of one calendar, or of every configured calendar. Until the next refresh
     * the events of those calendars cannot be read.
     *
     * @throws CirruxException
     */
    public function forget(?string $calendarUuid = null): void
    {
        $this->each($calendarUuid, $this->forgetCalendar->handle(...));
    }

    /**
     * @param  Closure(string): void  $action
     */
    private function each(?string $calendarUuid, Closure $action): void
    {
        $failures = [];

        foreach ($calendarUuid === null ? $this->configuredCalendars->uuids() : [$calendarUuid] as $uuid) {
            try {
                $action($uuid);
            } catch (CirruxException $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures !== []) {
            throw $failures[0];
        }
    }
}
