<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Calendars;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The events of the next weeks per calendar, one cache bucket per ISO week (UTC).
 *
 * An event sits in every week it overlaps. A bucket that is missing means "no data", an empty
 * bucket means "no events": read() answers null for the first so callers fail closed.
 * Everything lives as long as `cirrux.cache.ttl` (last known good). One metadata value per
 * calendar holds `refreshed_at` and the window `[from, to)` that refresh wrote, in the same
 * write as the buckets. Only that window may be read, and only while it is not older than
 * `cirrux.cache.max_staleness`: a bucket outside it comes from an older refresh and is never trusted.
 *
 * The cache holds plain arrays instead of DTOs: apps that set
 * `cache.serializable_classes` to false cannot unserialize objects.
 *
 * add() and remove() read and write a bucket in two steps, so callers take the calendar lock.
 */
final readonly class EventCache
{
    public function __construct(private Repository $cache) {}

    /**
     * The window a refresh covers: the current ISO week up to `weeks_ahead` weeks later.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function refreshWindow(): array
    {
        $from = $this->weekStart(CarbonImmutable::now('UTC'));

        return [$from, $from->addWeeks(Config::integer('cirrux.cache.weeks_ahead'))];
    }

    /**
     * Replaces every week in `[from, to)` in one write, empty weeks included.
     *
     * @param  Collection<int, CalendarEvent>  $events  only what overlaps `[from, to)`
     */
    public function replace(string $calendar, CarbonImmutable $from, CarbonImmutable $to, Collection $events, CarbonImmutable $refreshedAt): void
    {
        $buckets = array_fill_keys($this->bucketKeys($calendar, $from, $to), []);

        foreach ($events as $event) {
            foreach ($this->eventKeys($calendar, $event) as $key) {
                if (array_key_exists($key, $buckets)) {
                    $buckets[$key][] = $event->toArray();
                }
            }
        }

        $this->cache->setMultiple(
            [...$buckets, $this->metaKey($calendar) => [
                'refreshed_at' => $refreshedAt->utc()->toIso8601String(),
                'from' => $from->utc()->toIso8601String(),
                'to' => $to->utc()->toIso8601String(),
            ]],
            Config::integer('cirrux.cache.ttl'),
        );
    }

    /**
     * Every event that overlaps `[from, to)`, once per id, sorted on start.
     *
     * @return Collection<int, CalendarEvent>|null null when the cache cannot vouch for the window: the metadata
     *                                             is missing or damaged, older than `max_staleness`, does not cover
     *                                             the whole window, or a bucket is missing
     */
    public function read(string $calendar, CarbonImmutable $from, CarbonImmutable $to): ?Collection
    {
        $coverage = $this->coverage($calendar);

        if ($coverage === null || $this->isStale($coverage['refreshedAt']) || $from->lessThan($coverage['from']) || $to->greaterThan($coverage['to'])) {
            return null;
        }

        $buckets = $this->cache->getMultiple($this->bucketKeys($calendar, $from, $to));
        $events = [];

        foreach ($buckets as $bucket) {
            if (! is_array($bucket)) {
                return null;
            }

            foreach ($bucket as $row) {
                $event = CalendarEvent::fromArray(Arr::wrap($row));

                if ($event->start->lessThan($to) && $event->end->greaterThan($from)) {
                    $events[$event->id] ??= $event;
                }
            }
        }

        return collect($events)
            ->sort(static fn (CalendarEvent $a, CalendarEvent $b): int => $a->start <=> $b->start)
            ->values();
    }

    /**
     * Adds the event to the buckets that exist inside the covered window; a missing bucket stays
     * missing, because a bucket holding one event would look complete.
     */
    public function add(string $calendar, CalendarEvent $event): void
    {
        foreach ($this->coveredKeys($calendar, $this->eventKeys($calendar, $event)) as $key) {
            $bucket = $this->cache->get($key);

            if (! is_array($bucket)) {
                continue;
            }

            $rows = $this->withoutEvent($bucket, $event->id);
            $rows[] = $event->toArray();

            $this->cache->put($key, $rows, Config::integer('cirrux.cache.ttl'));
        }
    }

    /**
     * Removes the event from the buckets that exist inside the covered window. Without the
     * event itself every week of the covered window is checked.
     */
    public function remove(string $calendar, string $eventId, ?CalendarEvent $event = null): void
    {
        $keys = $event instanceof CalendarEvent ? $this->eventKeys($calendar, $event) : null;

        foreach ($this->coveredKeys($calendar, $keys) as $key) {
            $bucket = $this->cache->get($key);

            if (! is_array($bucket)) {
                continue;
            }

            $this->cache->put($key, $this->withoutEvent($bucket, $eventId), Config::integer('cirrux.cache.ttl'));
        }
    }

    /**
     * Forgets the metadata, which makes everything fail closed, and the buckets that may exist.
     */
    public function forget(string $calendar): void
    {
        $thisWeek = $this->weekStart(CarbonImmutable::now('UTC'));
        $weeksAhead = Config::integer('cirrux.cache.weeks_ahead');

        $this->cache->forget($this->metaKey($calendar));
        $this->cache->deleteMultiple($this->bucketKeys($calendar, $thisWeek->subWeek(), $thisWeek->addWeeks($weeksAhead + 2)));
    }

    /**
     * Whether the metadata is there and not older than `max_staleness`.
     */
    public function isFresh(string $calendar): bool
    {
        $coverage = $this->coverage($calendar);

        return $coverage !== null && ! $this->isStale($coverage['refreshedAt']);
    }

    private function isStale(CarbonImmutable $refreshedAt): bool
    {
        return $refreshedAt->addSeconds(Config::integer('cirrux.cache.max_staleness'))->isPast();
    }

    /**
     * What the last refresh wrote, or null when the metadata is missing or damaged.
     *
     * @return array{refreshedAt: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}|null
     */
    private function coverage(string $calendar): ?array
    {
        $meta = $this->cache->get($this->metaKey($calendar));

        if (! is_array($meta)) {
            return null;
        }

        $refreshedAt = $meta['refreshed_at'] ?? null;
        $from = $meta['from'] ?? null;
        $to = $meta['to'] ?? null;

        if (! is_string($refreshedAt) || ! is_string($from) || ! is_string($to)) {
            return null;
        }

        try {
            return [
                'refreshedAt' => CarbonImmutable::parse($refreshedAt)->utc(),
                'from' => CarbonImmutable::parse($from)->utc(),
                'to' => CarbonImmutable::parse($to)->utc(),
            ];
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * The given buckets, or without a list every bucket, that lie inside the covered window.
     * Nothing is covered without valid metadata.
     *
     * @param  list<string>|null  $keys
     * @return list<string>
     */
    private function coveredKeys(string $calendar, ?array $keys): array
    {
        $coverage = $this->coverage($calendar);

        if ($coverage === null) {
            return [];
        }

        $covered = $this->bucketKeys($calendar, $coverage['from'], $coverage['to']);

        return $keys === null ? $covered : array_values(array_intersect($keys, $covered));
    }

    /**
     * @param  array<array-key, mixed>  $bucket
     * @return list<mixed>
     */
    private function withoutEvent(array $bucket, string $eventId): array
    {
        return array_values(array_filter(
            $bucket,
            static fn (mixed $row): bool => Arr::wrap($row)['id'] !== $eventId,
        ));
    }

    /**
     * The buckets of the weeks that overlap `[from, to)`.
     *
     * @return list<string>
     */
    private function bucketKeys(string $calendar, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $keys = [];

        for ($week = $this->weekStart($from); $week->lessThan($to); $week = $week->addWeek()) {
            $keys[] = $this->bucketKey($calendar, $week);
        }

        return $keys;
    }

    /**
     * The buckets of the weeks the event overlaps.
     *
     * @return list<string>
     */
    private function eventKeys(string $calendar, CalendarEvent $event): array
    {
        // The end is exclusive: an event that ends exactly on Monday 00:00 does not reach that week.
        $to = $event->end->greaterThan($event->start) ? $event->end : $event->start->addMicrosecond();

        return $this->bucketKeys($calendar, $event->start, $to);
    }

    private function weekStart(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->utc()->startOfWeek(CarbonInterface::MONDAY);
    }

    private function bucketKey(string $calendar, CarbonImmutable $weekStart): string
    {
        return sprintf('cirrux:events:%s:%s', $calendar, $weekStart->format('o-\WW'));
    }

    private function metaKey(string $calendar): string
    {
        return 'cirrux:refreshed:'.$calendar;
    }
}
