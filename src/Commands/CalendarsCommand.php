<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Commands;

use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('List your Cirrux calendars with their UUIDs')]
#[Signature('cirrux:calendars')]
final class CalendarsCommand extends Command
{
    /**
     * Always asks Cirrux (and refreshes the cached list), so the UUIDs you copy into the config are current.
     */
    public function handle(CalendarList $calendars): int
    {
        try {
            $list = $calendars->refresh();
        } catch (CirruxAuthException $cirruxAuthException) {
            $this->components->error($cirruxAuthException->getMessage());
            $this->components->info('Run "php artisan cirrux:login" to log in to Cirrux.');

            return self::FAILURE;
        } catch (CirruxException $cirruxException) {
            $this->components->error($cirruxException->getMessage());

            return self::FAILURE;
        }

        if ($list->isEmpty()) {
            $this->components->warn('This Cirrux account has no calendars.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Mailbox', 'UUID', 'Writable', 'Color'],
            $list->map(static fn (Calendar $calendar): array => [
                $calendar->name,
                $calendar->mailboxAddress ?? '-',
                $calendar->uuid,
                $calendar->canWrite ? 'yes' : 'no',
                $calendar->color ?? '-',
            ])->all(),
        );

        if ($list->contains(static fn (Calendar $calendar): bool => ! $calendar->canWrite)) {
            $this->components->warn('A calendar that is not writable cannot take bookings: Cirrux refuses events there.');
        }

        return self::SUCCESS;
    }
}
