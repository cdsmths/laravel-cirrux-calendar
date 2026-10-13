<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The event moved in Cirrux. The booking holds the new times.
 */
final readonly class BookingRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Booking $booking,
        public CarbonImmutable $previousStart,
        public CarbonImmutable $previousEnd,
    ) {}
}
