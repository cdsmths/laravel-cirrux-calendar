<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
