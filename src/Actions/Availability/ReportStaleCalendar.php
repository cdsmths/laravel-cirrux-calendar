<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Availability;

use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Events\CalendarStale;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;

final readonly class ReportStaleCalendar
{
    public function __construct(
        private EventCache $cache,
        private Repository $store,
    ) {}

    /**
     * Announces a stale calendar, at most once per `cirrux.cache.max_staleness`: a busy booking
     * page would otherwise raise an alert on every visit.
     */
    public function handle(string $calendarUuid): void
    {
        if (! $this->store->add('cirrux:stale-reported:'.$calendarUuid, true, Config::integer('cirrux.cache.max_staleness'))) {
            return;
        }

        event(new CalendarStale($calendarUuid, $this->cache->refreshedAt($calendarUuid)));
    }
}
