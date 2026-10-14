<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

use Throwable;

/**
 * A request to Cirrux failed. The exception code holds the HTTP status, or 0
 * when no response was received.
 */
final class CirruxRequestException extends CirruxException
{
    /**
     * @param  string|null  $error  the machine-readable `error` code Cirrux answered with, such as
     *                              `not_found`, `calendar_read_only` or `subscription_required`
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly ?string $error = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
