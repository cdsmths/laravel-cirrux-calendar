<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The refresh failed for good; someone has to log in again.
 */
final readonly class TokenRefreshFailed
{
    use Dispatchable;

    public function __construct(public CirruxAuthException $exception) {}
}
