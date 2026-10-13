<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class BookingRejected
{
    use Dispatchable;

    /**
     * @param  string  $reason  a SlotRejection value (`off_grid`, `outside_hours`, `too_soon`, `too_far_ahead`, `in_past`, `ambiguous_time`), or `busy`, `lock_timeout` or `vetoed`
     */
    public function __construct(
        public string $eventType,
        public CarbonImmutable $start,
        public Attendee $attendee,
        public string $reason,
    ) {}
}
