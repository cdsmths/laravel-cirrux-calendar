<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Commands;

use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Calendars\ConfiguredCalendars;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Refresh the cached events of your Cirrux calendars')]
#[Signature('cirrux:refresh {calendar? : The UUID of one calendar, by default every calendar in the event types}')]
final class RefreshCommand extends Command
{
    /**
     * Tries every calendar and exits with a failure when one of them could not be refreshed.
     */
    public function handle(RefreshCalendar $refresh, ConfiguredCalendars $configured): int
    {
        $calendar = $this->argument('calendar');
        $uuids = is_string($calendar) ? [$calendar] : $configured->uuids();

        if ($uuids === []) {
            $this->components->warn('No calendars are configured. Set "calendar" on an event type in config/cirrux.php.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($uuids as $uuid) {
            try {
                $refresh->handle($uuid);
            } catch (CirruxException $cirruxException) {
                $failed = true;
                $this->components->error(sprintf('%s failed: %s', $uuid, $cirruxException->getMessage()));

                continue;
            }

            $this->components->info($uuid.' refreshed.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
