<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * No booking with that id exists.
 */
final class BookingNotFoundException extends CirruxException
{
    public function __construct(public readonly string $bookingId)
    {
        parent::__construct(sprintf('No booking with id "%s" exists.', $bookingId));
    }
}
