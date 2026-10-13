<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Codesmiths\CirruxCalendar\Data\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class BookingCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Booking $booking,
    ) {}
}
