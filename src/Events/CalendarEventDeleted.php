<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class CalendarEventDeleted
{
    use Dispatchable;

    public function __construct(
        public string $calendarUuid,
        public string $eventId,
    ) {}
}
