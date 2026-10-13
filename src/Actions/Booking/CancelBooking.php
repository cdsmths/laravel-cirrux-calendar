<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Booking;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Actions\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Events\BookingCancelled;
use Codesmiths\CirruxCalendar\Exceptions\BookingNotFoundException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Config;
use Throwable;

final readonly class CancelBooking
{
    /**
     * The same lock and hold time as RefreshCalendar and BookSlot.
     */
    private const int LOCK_SECONDS = 120;

    public function __construct(
        private DeleteEvent $deleteEvent,
        private RefreshCalendar $refreshCalendar,
        private LockProvider $locks,
    ) {}

    /**
     * Cancels a booking: the event is deleted from the calendar (Cirrux mails the attendees a
     * cancellation), then the booking is marked cancelled by the user.
     *
     * Idempotent: a booking that is already cancelled, by the user or by a reconciliation, does
     * nothing and dispatches nothing. An event that is already gone from the calendar counts as deleted.
     * When deleting the event fails the booking stays as it was.
     *
     * Deleting the event of a series forgets the calendar cache, and refreshing it needs the lock
     * this action holds. So that refresh runs after the lock is released. A refresh that fails
     * does not undo the cancel: the cache stays forgotten and reads fail closed until the next
     * refresh succeeds (RefreshCalendar has announced the failure).
     *
     * @throws BookingNotFoundException when no booking has that id
     * @throws CirruxRequestException when Cirrux refuses or cannot be reached, or the calendar lock cannot be taken in time
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(Booking|string $booking): void
    {
        $id = $booking instanceof Booking ? $booking->id : $booking;
        $record = BookingRecord::query()->find($id) ?? throw new BookingNotFoundException($id);

        $refresh = false;

        try {
            $cancelled = $this->locks
                ->lock('cirrux:lock:'.$record->calendar_uuid, self::LOCK_SECONDS)
                ->block(Config::integer('cirrux.lock.timeout'), function () use ($record, &$refresh): ?Booking {
                    return $this->cancel($record, $refresh);
                });
        } catch (LockTimeoutException $lockTimeoutException) {
            throw new CirruxRequestException('Timed out waiting for another process to release the calendar.', 0, $lockTimeoutException);
        } finally {
            if ($refresh) {
                $this->refresh($record->calendar_uuid);
            }
        }

        if ($cancelled instanceof Booking) {
            event(new BookingCancelled($cancelled, CancelledBy::User));
        }
    }

    /**
     * With the calendar locked, so the row is read again: a reconciliation or another cancel may
     * have finished the job before we got the lock. Null means there is nothing left to do.
     */
    private function cancel(BookingRecord $record, bool &$refresh): ?Booking
    {
        $record->refresh();

        if ($record->status === BookingStatus::Cancelled) {
            return null;
        }

        $refresh = $this->deleteEvent->handle($record->calendar_uuid, $record->event_id, false);

        $record->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_by' => CancelledBy::User,
            'cancelled_at' => CarbonImmutable::now('UTC'),
            'missing_since' => null,
        ])->save();

        return $record->toBooking();
    }

    private function refresh(string $calendarUuid): void
    {
        try {
            $this->refreshCalendar->handle($calendarUuid);
        } catch (CirruxException) {
            // RefreshCalendar has announced the failure; the cache stays forgotten and fails closed.
        } catch (Throwable $throwable) {
            // A failing reconciliation or listener must not undo a cancel that has happened.
            report($throwable);
        }
    }
}
