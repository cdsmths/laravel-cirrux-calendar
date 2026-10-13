<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

/**
 * A request to Cirrux failed. The exception code holds the HTTP status, or 0
 * when no response was received.
 */
final class CirruxRequestException extends CirruxException
{
    public function status(): int
    {
        return $this->getCode();
    }
}
