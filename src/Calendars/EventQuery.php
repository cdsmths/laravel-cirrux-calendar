<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Calendars;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Codesmiths\CirruxCalendar\Actions\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Actions\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Exceptions\StaleCalendarException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The events of one calendar: an immutable query that reads, creates and deletes.
 *
 * Reads come from the week cache; fresh() skips it. Creating and deleting write through to the cache.
 */
final readonly class EventQuery
{
    private const int LOOKBACK_HOURS = 24;

    private function __construct(
        private string $calendarUuid,
        private CirruxConnector $connector,
        private EventCache $cache,
        private CreateEvent $createEvent,
        private DeleteEvent $deleteEvent,
        private ?CarbonImmutable $from,
        private ?CarbonImmutable $to,
        private bool $skipCache,
    ) {}

    public static function for(string $calendarUuid, CirruxConnector $connector, EventCache $cache, CreateEvent $createEvent, DeleteEvent $deleteEvent): self
    {
        return new self($calendarUuid, $connector, $cache, $createEvent, $deleteEvent, null, null, false);
    }

    /**
     * The window `[$from, $to)`: an event belongs to it when it overlaps it.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): self
    {
        return new self(
            $this->calendarUuid,
            $this->connector,
            $this->cache,
            $this->createEvent,
            $this->deleteEvent,
            CarbonImmutable::instance($from)->utc(),
            CarbonImmutable::instance($to)->utc(),
            $this->skipCache,
        );
    }

    /**
     * Reads from Cirrux instead of the week cache. Not for visitor paths: every call costs API requests.
     */
    public function fresh(): self
    {
        return new self($this->calendarUuid, $this->connector, $this->cache, $this->createEvent, $this->deleteEvent, $this->from, $this->to, true);
    }

    /**
     * Every event that overlaps the window, once per id.
     *
     * By default this reads the week cache, sorted on start, without a single API call. When the
     * cache cannot vouch for the window the read fails closed instead of answering "no events".
     * After fresh() Cirrux is asked instead, over all pages, in the order Cirrux sent them.
     *
     * Cirrux is asked for `[from - 24h, to)` and the result is filtered locally on real overlap
     * with `[from, to)`. Whether Cirrux matches `time_min`/`time_max` on overlap or on start is
     * still open (plan: open point 3), so the lookback guarantees an event that starts before
     * `from` and runs into the window only up to 24 hours in length. A longer event that starts
     * before the lookback is found only if Cirrux filters on overlap.
     *
     * @return Collection<int, CalendarEvent>
     *
     * @throws LogicException without between()
     * @throws StaleCalendarException when the cache is missing, too old or does not cover the window (not after fresh())
     * @throws CirruxRequestException when a page fails (after fresh())
     * @throws RateLimitedException when the rate limit is reached (after fresh())
     */
    public function get(): Collection
    {
        throw_if(! $this->from instanceof CarbonImmutable || ! $this->to instanceof CarbonImmutable, LogicException::class, 'Call between() before get().');

        $from = $this->from;
        $to = $this->to;

        if (! $this->skipCache) {
            return $this->cache->read($this->calendarUuid, $from, $to) ?? throw new StaleCalendarException($this->calendarUuid);
        }

        // Internally everything is UTC, so Cirrux is asked for UTC too.
        $paginator = $this->connector->paginate(new ListEvents($this->calendarUuid, $from->subHours(self::LOOKBACK_HOURS), $to, 'UTC'));

        $events = [];

        foreach ($paginator->collect() as $event) {
            if ($event instanceof CalendarEvent && $event->start->lessThan($to) && $event->end->greaterThan($from)) {
                $events[$event->id] ??= $event;
            }
        }

        return collect(array_values($events));
    }

    /**
     * @throws CirruxRequestException when Cirrux refuses or cannot be reached
     * @throws RateLimitedException when the rate limit is reached
     */
    public function create(NewEvent $event): CalendarEvent
    {
        return $this->createEvent->handle($this->calendarUuid, $event);
    }

    /**
     * Idempotent: an event that is already gone counts as deleted.
     *
     * @throws CirruxRequestException for every failure except a 404
     * @throws RateLimitedException when the rate limit is reached
     */
    public function delete(string $eventId): void
    {
        $this->deleteEvent->handle($this->calendarUuid, $eventId);
    }
}
