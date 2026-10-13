<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Exceptions;

use Throwable;

/**
 * Cirrux answered 429 or the local rate limiter refused the request.
 */
final class RateLimitedException extends CirruxException
{
    public function __construct(
        public readonly int $retryAfter,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Cirrux rate limit reached; retry in %d seconds.', $retryAfter), 0, $previous);
    }
}
