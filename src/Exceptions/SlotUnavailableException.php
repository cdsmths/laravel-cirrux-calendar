<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * The slot was valid but could not be booked: the calendar is busy at that time, or the
 * calendar lock could not be taken in time.
 */
final class SlotUnavailableException extends CirruxException
{
    public const string BUSY = 'busy';

    public const string LOCK_TIMEOUT = 'lock_timeout';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 'The requested slot is no longer free.');
    }

    public static function lockTimeout(): self
    {
        return new self(self::LOCK_TIMEOUT, 'Could not lock the calendar in time to book the slot.');
    }
}
