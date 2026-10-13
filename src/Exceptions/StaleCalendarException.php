<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * The week cache cannot vouch for the requested window: it was never filled, has expired, is
 * older than `cirrux.cache.max_staleness` or does not cover the window. "No data" is never
 * read as "no events", so the read fails closed.
 */
final class StaleCalendarException extends CirruxException
{
    public function __construct(public readonly string $calendarUuid)
    {
        parent::__construct(sprintf(
            'The cached events of calendar %s are missing or too old. Run `php artisan cirrux:refresh` (or schedule it), or use fresh() outside visitor paths.',
            $calendarUuid,
        ));
    }
}
