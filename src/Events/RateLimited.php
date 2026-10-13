<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cirrux answered 429 or the local rate limiter refused a request.
 */
final readonly class RateLimited
{
    use Dispatchable;

    /**
     * @param  int  $retryAfter  seconds until a new request is allowed
     */
    public function __construct(public int $retryAfter) {}
}
