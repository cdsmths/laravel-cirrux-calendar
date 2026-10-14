<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Slot;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The slot is valid and free, and nothing has been written yet. A listener holds the booking
 * back by throwing a BookingVetoedException. `$title`, `$url` and `$location` are what the event will get in the calendar.
 */
final readonly class BookingCreating
{
    use Dispatchable;

    public function __construct(
        public string $eventType,
        public Slot $slot,
        public Attendee $attendee,
        public ?string $notes,
        public string $title,
        public ?string $url = null,
        public ?string $location = null,
    ) {}
}
