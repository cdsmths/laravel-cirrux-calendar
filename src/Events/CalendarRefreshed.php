<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The week cache of a calendar now holds the window `[from, to)` as Cirrux has it.
 */
final readonly class CalendarRefreshed
{
    use Dispatchable;

    /**
     * @param  int  $eventCount  the number of distinct events in the window
     * @param  int  $apiCalls  the number of pages fetched from Cirrux
     */
    public function __construct(
        public string $calendarUuid,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $eventCount,
        public int $apiCalls,
    ) {}
}
