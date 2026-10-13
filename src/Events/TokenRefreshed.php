<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class TokenRefreshed
{
    use Dispatchable;

    public function __construct(public CarbonImmutable $expiresAt) {}
}
