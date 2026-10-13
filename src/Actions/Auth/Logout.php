<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Auth;

use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Events\LoggedOut;

final readonly class Logout
{
    public function __construct(private TokenStore $tokens) {}

    public function handle(): void
    {
        $this->tokens->forget();

        event(new LoggedOut());
    }
}
