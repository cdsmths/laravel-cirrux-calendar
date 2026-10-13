<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Commands;

use Codesmiths\CirruxCalendar\Actions\Auth\Logout;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'cirrux:logout', description: 'Remove the stored Cirrux tokens')]
final class LogoutCommand extends Command
{
    public function handle(Logout $logout): int
    {
        $logout->handle();

        $this->components->info('Logged out of Cirrux.');

        return self::SUCCESS;
    }
}
