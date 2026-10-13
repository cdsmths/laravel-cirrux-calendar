<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Availability;

/**
 * Why a start is not a valid slot start for an event type, whatever the calendar holds.
 */
enum SlotRejection: string
{
    /** Not on the slot interval, counted from the start of the period it falls in (or has seconds). */
    case OffGrid = 'off_grid';

    /** The slot does not fit inside one of the working-hour periods of that local day. */
    case OutsideHours = 'outside_hours';

    /** Closer to now than `min_notice`. */
    case TooSoon = 'too_soon';

    /** At or beyond `max_days_ahead` from now. */
    case TooFarAhead = 'too_far_ahead';

    case InPast = 'in_past';

    /** The start or end is a wall-clock time that occurs twice or not at all, or the slot straddles a DST change. */
    case AmbiguousTime = 'ambiguous_time';
}
