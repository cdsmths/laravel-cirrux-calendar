<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Availability;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Codesmiths\CirruxCalendar\Actions\Availability\ReportStaleCalendar;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Data\Slot;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Exceptions\StaleCalendarException;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * The free slots of one event type: an immutable query on top of the week cache and the SlotCalculator.
 *
 * `slots()` is the visitor path: it reads the cache and never calls Cirrux. `isFree()` is not.
 */
final readonly class Availability
{
    public function __construct(
        private EventType $type,
        private CalendarResource $calendar,
        private SlotCalculator $calculator,
        private ReportStaleCalendar $reportStale,
        private ?CarbonImmutable $from = null,
        private ?CarbonImmutable $to = null,
        private ?string $timezone = null,
    ) {}

    /**
     * The window `[$from, $to)` in which a slot may start.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): self
    {
        return new self(
            $this->type,
            $this->calendar,
            $this->calculator,
            $this->reportStale,
            CarbonImmutable::instance($from)->utc(),
            CarbonImmutable::instance($to)->utc(),
            $this->timezone,
        );
    }

    /**
     * The zone the slots are handed out in. Presentation only: the instants do not change.
     *
     * @throws InvalidArgumentException for an unknown time zone
     */
    public function inTimezone(string $timezone): self
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid time zone identifier.', $timezone));
        }

        return new self($this->type, $this->calendar, $this->calculator, $this->reportStale, $this->from, $this->to, $timezone);
    }

    /**
     * Every free slot that starts in the window, by start. The window is clipped to now and to
     * `max_days_ahead`; a window that is empty after that returns nothing without reading the cache.
     *
     * Reads the week cache and never calls Cirrux, so it is safe on pages that visitors open. When the
     * cache cannot vouch for the window the result is empty (fail closed) and `CalendarStale` is
     * dispatched, at most once per `max_staleness`. Other exceptions are not caught.
     *
     * @return Collection<int, Slot>
     *
     * @throws LogicException without between()
     */
    public function slots(): Collection
    {
        throw_if(! $this->from instanceof CarbonImmutable || ! $this->to instanceof CarbonImmutable, LogicException::class, 'Call between() before slots().');

        $now = CarbonImmutable::now('UTC');
        $from = $this->from->greaterThan($now) ? $this->from : $now;
        $horizon = $now->addHours($this->type->maxDaysAhead * 24);
        $to = $this->to->lessThan($horizon) ? $this->to : $horizon;

        if ($from->greaterThanOrEqualTo($to)) {
            return new Collection;
        }

        [$eventsFrom, $eventsTo] = $this->calculator->eventsWindow($this->type, $from, $to);

        try {
            $events = $this->calendar->events()->between($eventsFrom, $eventsTo)->get();
        } catch (StaleCalendarException $staleCalendarException) {
            $this->reportStale->handle($staleCalendarException->calendarUuid);

            return new Collection;
        }

        $timezone = $this->timezone;

        return $this->calculator->slots($this->type, $from, $to, $events, $now)
            ->map(static fn (Slot $slot): Slot => $timezone === null ? $slot : $slot->inTimezone($timezone));
    }

    /**
     * Whether a slot starting at `$start` can still be booked. A start that is not a valid slot
     * is not free, without a call to Cirrux. Otherwise Cirrux is asked, not the cache.
     *
     * Not for visitor pages: every call that gets this far costs API requests.
     *
     * @throws CirruxRequestException when a page fails
     * @throws RateLimitedException when the rate limit is reached
     */
    public function isFree(CarbonInterface $start): bool
    {
        $start = CarbonImmutable::instance($start)->utc();
        $now = CarbonImmutable::now('UTC');

        if ($this->calculator->rejectionFor($this->type, $start, $now) instanceof SlotRejection) {
            return false;
        }

        [$eventsFrom, $eventsTo] = $this->calculator->eventsWindow($this->type, $start, $start->addMinutes($this->type->duration));

        $events = $this->calendar->events()->fresh()->between($eventsFrom, $eventsTo)->get();

        return $this->calculator->isFree($this->type, $start, $events, $now);
    }
}
