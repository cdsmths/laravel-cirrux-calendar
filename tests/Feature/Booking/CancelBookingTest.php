<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\BookingCancelled;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshed;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\BookingNotFoundException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent as DeleteEventRequest;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
    Event::fake([BookingCancelled::class]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function cancelBookingRow(array $overrides = []): BookingRecord
{
    return BookingRecord::query()->create([
        'event_type' => 'demo',
        'calendar_uuid' => 'cal-1',
        'event_id' => 'evt-1',
        'start_at' => '2026-10-30 08:00:00',
        'end_at' => '2026-10-30 08:30:00',
        'attendee_email' => 'jan@example.com',
        'status' => BookingStatus::Confirmed,
        'missing_since' => '2026-10-28 11:00:00',
        ...$overrides,
    ]);
}

it('cancels a booking by id: delete the event, mark the row, dispatch BookingCancelled', function (int $status): void {
    $record = cancelBookingRow();
    Saloon::fake([DeleteEventRequest::class => MockResponse::make('', $status)]);

    Cirrux::cancel($record->id);

    $fresh = $record->fresh();

    expect($fresh?->status)->toBe(BookingStatus::Cancelled)
        ->and($fresh->cancelled_by)->toBe(CancelledBy::User)
        ->and($fresh->cancelled_at?->toIso8601ZuluString())->toBe('2026-10-28T12:00:00Z')
        ->and($fresh->missing_since)->toBeNull();

    Saloon::assertSentCount(1);
    Saloon::assertSent(fn (DeleteEventRequest $request): bool => $request->resolveEndpoint() === '/calendars/cal-1/events/evt-1');
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
    Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $event): bool => $event->booking->id === $record->id
        && $event->booking->status === BookingStatus::Cancelled
        && $event->cancelledBy === CancelledBy::User);
})->with([
    'deleted' => 204,
    'already gone' => 404,
]);

it('cancels a booking given as a Booking', function (): void {
    $record = cancelBookingRow();
    Saloon::fake([DeleteEventRequest::class => MockResponse::make('', 204)]);

    Cirrux::cancel($record->toBooking());

    expect($record->fresh()?->status)->toBe(BookingStatus::Cancelled);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('does nothing for a booking that is already cancelled', function (): void {
    $record = cancelBookingRow([
        'status' => BookingStatus::Cancelled,
        'cancelled_by' => CancelledBy::External,
        'cancelled_at' => '2026-10-27 09:00:00',
        'missing_since' => null,
    ]);
    Saloon::fake([]);

    Cirrux::cancel($record->id);
    Cirrux::cancel($record->toBooking());

    expect($record->fresh()?->cancelled_by)->toBe(CancelledBy::External)
        ->and($record->fresh()->cancelled_at?->toIso8601ZuluString())->toBe('2026-10-27T09:00:00Z');
    Saloon::assertSentCount(0);
    Event::assertNotDispatched(BookingCancelled::class);
});

it('trusts the row, not the booking it was handed', function (): void {
    $record = cancelBookingRow();
    $stale = $record->toBooking();
    $record->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::External])->save();
    Saloon::fake([]);

    Cirrux::cancel($stale);

    expect($record->fresh()?->cancelled_by)->toBe(CancelledBy::External);
    Saloon::assertSentCount(0);
    Event::assertNotDispatched(BookingCancelled::class);
});

it('refuses an unknown booking', function (): void {
    Saloon::fake([]);

    try {
        Cirrux::cancel('does-not-exist');
        $this->fail('Expected a BookingNotFoundException.');
    } catch (BookingNotFoundException $bookingNotFoundException) {
        expect($bookingNotFoundException->bookingId)->toBe('does-not-exist')
            ->and($bookingNotFoundException->getMessage())->toBe('No booking with id "does-not-exist" exists.');
    }

    Saloon::assertSentCount(0);
    Event::assertNotDispatched(BookingCancelled::class);
});

it('leaves the booking as it was when Cirrux refuses the delete', function (): void {
    $record = cancelBookingRow();
    Saloon::fake([DeleteEventRequest::class => MockResponse::make(['error' => 'boom'], 500)]);

    expect(fn () => Cirrux::cancel($record->id))->toThrow(CirruxRequestException::class);

    $fresh = $record->fresh();

    expect($fresh?->status)->toBe(BookingStatus::Confirmed)
        ->and($fresh->cancelled_by)->toBeNull()
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($fresh->missing_since)->not->toBeNull();
    Event::assertNotDispatched(BookingCancelled::class);

    $lock = Cache::lock('cirrux:lock:cal-1', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('fails with a request exception while another process holds the calendar lock', function (): void {
    config()->set('cirrux.lock.timeout', 0);
    $record = cancelBookingRow();
    Saloon::fake([]);
    $lock = Cache::lock('cirrux:lock:cal-1', 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => Cirrux::cancel($record->id))->toThrow(CirruxRequestException::class, 'Timed out waiting for another process to release the calendar.');
    } finally {
        $lock->release();
    }

    expect($record->fresh()?->status)->toBe(BookingStatus::Confirmed);
    Saloon::assertSentCount(0);
    Event::assertNotDispatched(BookingCancelled::class);
});

function cancelBookingFillSeries(): void
{
    $cache = resolve(EventCache::class);
    [$from, $to] = $cache->refreshWindow();

    $cache->replace('cal-1', $from, $to, collect([
        CalendarEvent::fromApi([
            'id' => 'evt-1',
            'event_uuid' => 'evt-1',
            'series_uuid' => 'series-1',
            'recurrence' => 'FREQ=WEEKLY',
            'title' => 'Weekly',
            'start' => ['date_time' => '2026-10-30T08:00:00Z', 'time_zone' => 'UTC'],
            'end' => ['date_time' => '2026-10-30T08:30:00Z', 'time_zone' => 'UTC'],
            'attendees' => [],
        ]),
    ]), CarbonImmutable::now('UTC'));
}

it('refreshes once, after the lock, when the cancelled event belongs to a series', function (): void {
    Event::fake([BookingCancelled::class, CalendarRefreshed::class, CalendarRefreshFailed::class]);
    // A refresh inside the lock would find it taken and fail at once.
    config()->set('cirrux.lock.timeout', 0);
    $record = cancelBookingRow();
    cancelBookingFillSeries();
    Saloon::fake([
        DeleteEventRequest::class => MockResponse::make('', 204),
        ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []]),
    ]);

    Cirrux::cancel($record->id);

    expect($record->fresh()?->status)->toBe(BookingStatus::Cancelled);
    Saloon::assertSentCount(2);
    Event::assertDispatchedTimes(CalendarRefreshed::class, 1);
    Event::assertNotDispatched(CalendarRefreshFailed::class);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('keeps the cancel when the refresh after a series delete fails', function (): void {
    Event::fake([BookingCancelled::class, CalendarRefreshFailed::class]);
    $record = cancelBookingRow();
    cancelBookingFillSeries();
    Saloon::fake([
        DeleteEventRequest::class => MockResponse::make('', 204),
        ListEvents::class => MockResponse::make(['error' => 'boom'], 500),
    ]);

    Cirrux::cancel($record->id);

    expect($record->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and(resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-28T12:00:00Z'), CarbonImmutable::parse('2026-10-29T12:00:00Z')))->toBeNull();
    Event::assertDispatchedTimes(CalendarRefreshFailed::class, 1);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('does not refresh when the event is not part of a series', function (): void {
    $record = cancelBookingRow();
    Saloon::fake([DeleteEventRequest::class => MockResponse::make('', 204)]);

    Cirrux::cancel($record->id);

    Saloon::assertNotSent(ListEvents::class);
});

/**
 * Arms a failure for the reconciliation that follows the refresh: the listing arms it, and the
 * next confirmed row that is read throws.
 */
function cancelBookingBreakReconciliation(): void
{
    cancelBookingRow(['event_id' => 'evt-other', 'start_at' => '2026-11-03 08:00:00', 'end_at' => '2026-11-03 08:30:00']);
    $armed = false;
    BookingRecord::retrieved(function () use (&$armed): void {
        if ($armed) {
            // Once: the reads of the test itself must work.
            $armed = false;

            throw new QueryException('sqlite', 'select 1', [], new RuntimeException('database is gone'));
        }
    });
    Saloon::fake([
        DeleteEventRequest::class => MockResponse::make('', 204),
        ListEvents::class => function () use (&$armed): MockResponse {
            $armed = true;

            return MockResponse::make(['has_more' => false, 'data' => []]);
        },
    ]);
}

it('still cancels when the reconciliation after a series delete fails', function (): void {
    $record = cancelBookingRow();
    cancelBookingFillSeries();
    $reported = [];
    cancelBookingBreakReconciliation();
    resolve(ExceptionHandler::class)->reportable(function (Throwable $throwable) use (&$reported): bool {
        $reported[] = $throwable;

        return false;
    });

    Cirrux::cancel($record->id);

    expect($record->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(QueryException::class);
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});

it('still cancels when a CalendarRefreshed listener throws after a series delete', function (): void {
    $record = cancelBookingRow();
    cancelBookingFillSeries();
    Saloon::fake([
        DeleteEventRequest::class => MockResponse::make('', 204),
        ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []]),
    ]);
    Event::listen(CalendarRefreshed::class, function (): never {
        throw new RuntimeException('listener broke');
    });
    $reported = [];
    resolve(ExceptionHandler::class)->reportable(function (Throwable $throwable) use (&$reported): bool {
        $reported[] = $throwable;

        return false;
    });

    Cirrux::cancel($record->id);

    expect($record->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->getMessage())->toBe('listener broke');
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
});
