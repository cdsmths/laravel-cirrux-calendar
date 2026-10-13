<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Cache;

use Codesmiths\CirruxCalendar\Calendars\EventCache;

final readonly class ForgetCalendar
{
    public function __construct(private EventCache $cache) {}

    /**
     * Drops the cached events of a calendar; until the next refresh every read fails closed.
     */
    public function handle(string $calendarUuid): void
    {
        $this->cache->forget($calendarUuid);
    }
}
