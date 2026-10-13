<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Booking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Actions\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Actions\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Availability\SlotCalculator;
use Codesmiths\CirruxCalendar\Availability\SlotRejection;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Slot;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Events\BookingCreated;
use Codesmiths\CirruxCalendar\Events\BookingCreating;
use Codesmiths\CirruxCalendar\Events\BookingRejected;
use Codesmiths\CirruxCalendar\Exceptions\BookingVetoedException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidSlotException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Exceptions\SlotUnavailableException;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use Throwable;

final readonly class BookSlot
{
    /**
     * The same lock and hold time as RefreshCalendar, so booking, cancelling, refreshing and
     * reconciling take turns on one calendar. A crashed process releases it after this long.
     */
    private const int LOCK_SECONDS = 120;

    public function __construct(
        private Container $container,
        private SlotCalculator $calculator,
        private CreateEvent $createEvent,
        private DeleteEvent $deleteEvent,
        private RefreshCalendar $refreshCalendar,
        private LockProvider $locks,
    ) {}

    /**
     * Books one slot of a configured event type and returns the booking.
     *
     * The start is checked against the event type, then, with the calendar locked, against what
     * Cirrux holds right now (not the cache). Listeners of `BookingCreating` can still veto the
     * booking by throwing a BookingVetoedException. Then the event is created (Cirrux mails the
     * attendee an invitation) and the booking is stored. When storing fails the event is deleted
     * again and the original exception is thrown.
     *
     * When Cirrux creates the event but the answer does not reach us (a timeout), no booking row
     * exists while the event does: the next refresh shows that time as busy.
     *
     * Every refusal dispatches `BookingRejected`, after the lock is released. `BookingCreated` is
     * dispatched last, and only when the row is stored.
     *
     * @param  string|null  $title  the title of this event in the calendar; null, empty or only
     *                              whitespace falls back to the title of the event type
     *
     * @throws InvalidConfigurationException for an unknown or invalid event type, or one without a calendar
     * @throws InvalidSlotException when the start is not a valid slot start of the event type
     * @throws SlotUnavailableException when the calendar is busy at that time, or could not be locked in time
     * @throws BookingVetoedException when a listener of BookingCreating holds the booking back
     * @throws CirruxRequestException when Cirrux refuses or cannot be reached
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(string $eventType, CarbonInterface $start, Attendee $attendee, ?string $notes = null, ?string $title = null): Booking
    {
        $type = $this->eventType($eventType);
        $title = $this->title($type, $title);
        $calendar = (string) $type->calendar;
        $start = CarbonImmutable::instance($start)->utc();

        $rejection = $this->calculator->rejectionFor($type, $start, CarbonImmutable::now('UTC'));

        if ($rejection instanceof SlotRejection) {
            event(new BookingRejected($eventType, $start, $attendee, $rejection->value));

            throw new InvalidSlotException($rejection);
        }

        $refresh = false;

        try {
            /** @var Booking $booking */
            $booking = $this->locks
                ->lock('cirrux:lock:'.$calendar, self::LOCK_SECONDS)
                ->block(Config::integer('cirrux.lock.timeout'), function () use ($type, $calendar, $start, $attendee, $notes, $title, &$refresh): Booking {
                    return $this->book($type, $calendar, $start, $attendee, $notes, $title, $refresh);
                });
        } catch (LockTimeoutException) {
            event(new BookingRejected($eventType, $start, $attendee, SlotUnavailableException::LOCK_TIMEOUT));

            throw SlotUnavailableException::lockTimeout();
        } catch (SlotUnavailableException $slotUnavailableException) {
            event(new BookingRejected($eventType, $start, $attendee, $slotUnavailableException->reason));

            throw $slotUnavailableException;
        } catch (BookingVetoedException $bookingVetoedException) {
            event(new BookingRejected($eventType, $start, $attendee, 'vetoed'));

            throw $bookingVetoedException;
        } finally {
            if ($refresh) {
                $this->refresh($calendar);
            }
        }

        event(new BookingCreated($booking));

        return $booking;
    }

    /**
     * Everything that must happen with the calendar locked.
     */
    private function book(EventType $type, string $calendar, CarbonImmutable $start, Attendee $attendee, ?string $notes, string $title, bool &$refresh): Booking
    {
        $end = $start->addMinutes($type->duration);

        [$eventsFrom, $eventsTo] = $this->calculator->eventsWindow($type, $start, $end);
        $events = (new CalendarResource($calendar, $this->container))->events()->fresh()->between($eventsFrom, $eventsTo)->get();

        throw_unless($this->calculator->isFree($type, $start, $events, CarbonImmutable::now('UTC')), SlotUnavailableException::busy());

        event(new BookingCreating($type->name, new Slot($start, $end), $attendee, $notes, $title));

        $created = $this->createEvent->handle($calendar, new NewEvent(
            title: $title,
            start: $start,
            end: $end,
            timezone: $type->timezone,
            description: $notes,
            transparency: Transparency::Opaque,
            attendees: [$attendee],
        ));

        try {
            $record = BookingRecord::query()->create([
                'event_type' => $type->name,
                'calendar_uuid' => $calendar,
                'event_id' => $created->id,
                'start_at' => $start,
                'end_at' => $end,
                'attendee_email' => $attendee->email,
                'attendee_name' => $attendee->name,
                'notes' => $notes,
                'status' => BookingStatus::Confirmed,
            ]);
        } catch (Throwable $throwable) {
            $refresh = $this->undo($calendar, $created->id);

            throw $throwable;
        }

        return $record->toBooking();
    }

    /**
     * Takes the event of a booking that could not be stored out of the calendar again. A failure
     * here is reported, not thrown: the caller gets the exception that started it all.
     *
     * Returns whether the calendar cache was forgotten and has to be refreshed once the lock is released.
     */
    private function undo(string $calendar, string $eventId): bool
    {
        try {
            return $this->deleteEvent->handle($calendar, $eventId, false);
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    /**
     * After the lock is released. A refresh that fails has announced itself; the cache stays
     * forgotten and reads fail closed until the next refresh succeeds.
     */
    private function refresh(string $calendar): void
    {
        try {
            $this->refreshCalendar->handle($calendar);
        } catch (CirruxException) {
            // RefreshCalendar has announced the failure.
        } catch (Throwable $throwable) {
            // A failing reconciliation or listener must not hide the exception that started the undo.
            report($throwable);
        }
    }

    /**
     * The title for this booking's event, trimmed; without one, the title of the event type.
     */
    private function title(EventType $type, ?string $title): string
    {
        $title = trim($title ?? '');

        return $title === '' ? $type->title : $title;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function eventType(string $name): EventType
    {
        $key = 'cirrux.event_types.'.$name;
        $config = config($key);

        if (! is_array($config)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        $type = EventType::fromConfig($name, $config);

        if ($type->calendar === null || $type->calendar === '') {
            throw InvalidConfigurationException::forKey($key.'.calendar', 'must be the uuid of a calendar');
        }

        return $type;
    }
}
