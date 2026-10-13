<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Codesmiths\CirruxCalendar\Data\CirruxFailure;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The refresh failed; the cache keeps what it had.
 */
final readonly class CalendarRefreshFailed
{
    use Dispatchable;

    public function __construct(
        public string $calendarUuid,
        public CirruxFailure $failure,
    ) {}
}
