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
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The events of one calendar: an immutable query that reads, creates and deletes.
 *
 * Until the week cache exists (phase 3) every read goes to Cirrux, with or without fresh().
 * Phase 3 makes the cache the default for get(); fresh() is the flag that will skip it.
 */
final readonly class EventQuery
{
    private const int LOOKBACK_HOURS = 24;

    private function __construct(
        private string $calendarUuid,
        private CirruxConnector $connector,
        private CreateEvent $createEvent,
        private DeleteEvent $deleteEvent,
        private ?CarbonImmutable $from,
        private ?CarbonImmutable $to,
        private bool $skipCache, // @phpstan-ignore property.onlyWritten (phase 3 reads it to bypass the week cache)
    ) {}

    public static function for(string $calendarUuid, CirruxConnector $connector, CreateEvent $createEvent, DeleteEvent $deleteEvent): self
    {
        return new self($calendarUuid, $connector, $createEvent, $deleteEvent, null, null, false);
    }

    /**
     * The window `[$from, $to)`: an event belongs to it when it overlaps it.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): self
    {
        return new self(
            $this->calendarUuid,
            $this->connector,
            $this->createEvent,
            $this->deleteEvent,
            CarbonImmutable::instance($from)->utc(),
            CarbonImmutable::instance($to)->utc(),
            $this->skipCache,
        );
    }

    /**
     * Skips the cache. Not for visitor paths.
     */
    public function fresh(): self
    {
        return new self($this->calendarUuid, $this->connector, $this->createEvent, $this->deleteEvent, $this->from, $this->to, true);
    }

    /**
     * Every event that overlaps the window, over all pages, once per id, sorted as Cirrux sent them.
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
     * @throws CirruxRequestException when a page fails
     * @throws RateLimitedException when the rate limit is reached
     */
    public function get(): Collection
    {
        throw_if(! $this->from instanceof CarbonImmutable || ! $this->to instanceof CarbonImmutable, LogicException::class, 'Call between() before get().');

        $from = $this->from;
        $to = $this->to;

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
