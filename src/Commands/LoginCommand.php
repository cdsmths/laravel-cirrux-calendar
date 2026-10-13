<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Commands;

use Codesmiths\CirruxCalendar\Actions\Auth\CompleteDeviceLogin;
use Codesmiths\CirruxCalendar\Actions\Auth\StartDeviceLogin;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'cirrux:login', description: 'Log in to Cirrux with the device flow')]
final class LoginCommand extends Command
{
    public function handle(StartDeviceLogin $start, CompleteDeviceLogin $complete): int
    {
        try {
            $device = $start->handle();

            $this->components->info('Open this URL in a browser and enter the code to authorize the login.');
            $this->components->twoColumnDetail('URL', $device->verificationUri);
            $this->components->twoColumnDetail('Code', $device->userCode);
            $this->components->info('Waiting for approval...');

            $complete->handle($device);
        } catch (CirruxException $cirruxException) {
            $this->components->error($cirruxException->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Logged in to Cirrux.');

        return self::SUCCESS;
    }
}
