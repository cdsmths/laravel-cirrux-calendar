<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The cache could not vouch for a calendar, so availability returned no slots instead of guessing.
 * Dispatched at most once per `cirrux.cache.max_staleness` per calendar.
 */
final readonly class CalendarStale
{
    use Dispatchable;

    /**
     * @param  CarbonImmutable|null  $refreshedAt  null when there is no usable refresh metadata (never refreshed, forgotten, expired or damaged)
     */
    public function __construct(
        public string $calendarUuid,
        public ?CarbonImmutable $refreshedAt,
    ) {}
}
