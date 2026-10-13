<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Auth;

use Codesmiths\CirruxCalendar\Data\Tokens;

interface TokenStore
{
    public function get(): ?Tokens;

    /**
     * Replace the stored token pair in one atomic write.
     */
    public function put(Tokens $tokens): void;

    public function forget(): void;
}
