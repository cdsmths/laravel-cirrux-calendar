<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class CalendarEventCreated
{
    use Dispatchable;

    public function __construct(
        public string $calendarUuid,
        public CalendarEvent $event,
    ) {}
}
