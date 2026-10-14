<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Carbon\CarbonInterface;
use Closure;
use Codesmiths\CirruxCalendar\Actions\Availability\ReportStaleCalendar;
use Codesmiths\CirruxCalendar\Actions\Booking\BookSlot;
use Codesmiths\CirruxCalendar\Actions\Booking\CancelBooking;
use Codesmiths\CirruxCalendar\Actions\Cache\ForgetCalendar;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Availability\Availability;
use Codesmiths\CirruxCalendar\Availability\SlotCalculator;
use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Calendars\ConfiguredCalendars;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Exceptions\BookingNotFoundException;
use Codesmiths\CirruxCalendar\Exceptions\BookingVetoedException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidSlotException;
use Codesmiths\CirruxCalendar\Exceptions\SlotUnavailableException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
        private BookSlot $bookSlot,
        private CancelBooking $cancelBooking,
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
     * Books one slot of a configured event type: the start is validated, the calendar is checked
     * against Cirrux itself, the event is created (Cirrux mails the attendee) and the booking is stored.
     *
     * `$title` names this event in the calendar, `$url` links it (a video call, say) and `$location` places it.
     * Null, empty or only whitespace uses the value of the event type.
     *
     * @throws InvalidConfigurationException for an unknown event type, an invalid one or one without a calendar
     * @throws InvalidArgumentException when `$url` is not an http or https URL
     * @throws InvalidSlotException when the start is not a valid slot start of the event type
     * @throws SlotUnavailableException when the calendar is busy at that time, or could not be locked in time
     * @throws BookingVetoedException when a listener of BookingCreating holds the booking back
     * @throws CirruxException when Cirrux refuses or cannot be reached
     */
    public function book(string $eventType, CarbonInterface $start, Attendee $attendee, ?string $notes = null, ?string $title = null, ?string $url = null, ?string $location = null): Booking
    {
        return $this->bookSlot->handle($eventType, $start, $attendee, $notes, $title, $url, $location);
    }

    /**
     * Cancels a booking, by the booking or its id: the event is deleted from the calendar and Cirrux
     * mails the attendees. Cancelling a booking that is already cancelled does nothing.
     *
     * @throws BookingNotFoundException when no booking has that id
     * @throws CirruxException when Cirrux refuses or cannot be reached
     */
    public function cancel(Booking|string $booking): void
    {
        $this->cancelBooking->handle($booking);
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
