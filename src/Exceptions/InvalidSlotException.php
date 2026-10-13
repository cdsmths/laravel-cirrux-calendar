<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

use Codesmiths\CirruxCalendar\Availability\SlotRejection;

/**
 * The requested start is not a valid slot start for the event type, whatever the calendar holds.
 */
final class InvalidSlotException extends CirruxException
{
    public function __construct(public readonly SlotRejection $reason)
    {
        parent::__construct(sprintf('The requested start is not a valid slot start (%s).', $reason->value));
    }
}
