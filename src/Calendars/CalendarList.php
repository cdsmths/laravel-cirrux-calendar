<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Calendars;

use Codesmiths\CirruxCalendar\Data\Calendar;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Calendars\ListCalendars;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The calendars of the logged in user, read through the cache.
 *
 * The cache holds plain arrays instead of DTOs: apps that set
 * `cache.serializable_classes` to false cannot unserialize objects.
 */
final readonly class CalendarList
{
    private const string KEY = 'cirrux:calendars';

    public function __construct(
        private CirruxConnector $connector,
        private Repository $cache,
    ) {}

    /**
     * @return Collection<int, Calendar>
     */
    public function get(): Collection
    {
        $rows = $this->cache->remember(
            self::KEY,
            Config::integer('cirrux.cache.calendars_ttl'),
            fn (): array => $this->toRows($this->fetch()),
        );

        return $this->hydrate(Arr::wrap($rows));
    }

    /**
     * Asks Cirrux and replaces the cached list with the answer.
     *
     * @return Collection<int, Calendar>
     */
    public function refresh(): Collection
    {
        $calendars = $this->fetch();

        $this->cache->put(self::KEY, $this->toRows($calendars), Config::integer('cirrux.cache.calendars_ttl'));

        return $calendars;
    }

    /**
     * @return Collection<int, Calendar>
     */
    private function fetch(): Collection
    {
        /** @var Collection<int, Calendar> $calendars */
        $calendars = $this->connector->send(new ListCalendars)->dtoOrFail();

        return $calendars->values();
    }

    /**
     * @param  Collection<int, Calendar>  $calendars
     * @return array<int, array{uuid: string, name: string, color: string|null}>
     */
    private function toRows(Collection $calendars): array
    {
        return $calendars->map(static fn (Calendar $calendar): array => $calendar->toArray())->values()->all();
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return Collection<int, Calendar>
     */
    private function hydrate(array $rows): Collection
    {
        return collect($rows)
            ->map(static fn (mixed $row): Calendar => Calendar::fromArray(Arr::wrap($row)))
            ->values();
    }
}
