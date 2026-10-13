<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Booking;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Events\BookingCancelled;
use Codesmiths\CirruxCalendar\Events\BookingRescheduled;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ReconcileBookings
{
    /**
     * Brings the confirmed bookings of a calendar in line with the events of a refresh that just succeeded.
     *
     * Only bookings that lie completely inside the fetched window are looked at; for any other
     * booking the refresh says nothing. A booking whose event moved gets its new times and a
     * BookingRescheduled. A booking whose event is missing is only marked (`missing_since`); it
     * counts as cancelled externally when the event is missing again on the next successful
     * refresh. One incomplete list (a bug, faulty pagination) must not cancel every booking at once,
     * and there is no endpoint to fetch a single event to double-check.
     *
     * Limitation: an event that is moved in Cirrux beyond the refresh window (further than
     * `weeks_ahead` weeks) looks deleted, and is marked as cancelled externally after two refreshes.
     *
     * Call it with the calendar lock held, so it serializes with booking and cancelling.
     *
     * @param  Collection<int, CalendarEvent>  $events  the events of the window, as stored in the cache
     */
    public function handle(string $calendarUuid, CarbonImmutable $from, CarbonImmutable $to, Collection $events): void
    {
        $byId = $events->keyBy(static fn (CalendarEvent $event): string => $event->id);

        $records = BookingRecord::query()
            ->where('calendar_uuid', $calendarUuid)
            ->where('status', BookingStatus::Confirmed)
            ->where('start_at', '>=', $from->utc())
            ->where('end_at', '<=', $to->utc())
            ->get();

        foreach ($records as $record) {
            DB::transaction(function () use ($record, $byId): void {
                $event = $byId->get($record->event_id);

                if ($event instanceof CalendarEvent) {
                    $this->present($record, $event);
                } else {
                    $this->missing($record);
                }
            });
        }
    }

    private function present(BookingRecord $record, CalendarEvent $event): void
    {
        $previousStart = $record->start_at;
        $previousEnd = $record->end_at;
        $moved = ! $previousStart->equalTo($event->start) || ! $previousEnd->equalTo($event->end);

        if (! $moved && ! $record->missing_since instanceof CarbonImmutable) {
            return;
        }

        $record->missing_since = null;

        if ($moved) {
            $record->start_at = $event->start;
            $record->end_at = $event->end;
        }

        $record->save();

        if ($moved) {
            event(new BookingRescheduled($record->toBooking(), $previousStart->utc(), $previousEnd->utc()));
        }
    }

    private function missing(BookingRecord $record): void
    {
        $now = CarbonImmutable::now('UTC');

        if (! $record->missing_since instanceof CarbonImmutable) {
            $record->missing_since = $now;
            $record->save();

            return;
        }

        $record->status = BookingStatus::Cancelled;
        $record->cancelled_by = CancelledBy::External;
        $record->cancelled_at = $now;
        $record->missing_since = null;
        $record->save();

        event(new BookingCancelled($record->toBooking(), CancelledBy::External));
    }
}
