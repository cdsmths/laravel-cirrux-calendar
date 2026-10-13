<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Commands;

use Codesmiths\CirruxCalendar\Actions\Auth\Logout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Remove the stored Cirrux tokens')]
#[Signature('cirrux:logout')]
final class LogoutCommand extends Command
{
    public function handle(Logout $logout): int
    {
        $logout->handle();

        $this->components->info('Logged out of Cirrux.');

        return self::SUCCESS;
    }
}
