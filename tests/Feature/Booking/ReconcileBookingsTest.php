<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Booking\ReconcileBookings;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Events\BookingCancelled;
use Codesmiths\CirruxCalendar\Events\BookingRescheduled;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    Event::fake([BookingCancelled::class, BookingRescheduled::class]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function reconcileTestBooking(array $overrides = []): BookingRecord
{
    return BookingRecord::query()->create([
        'event_type' => 'demo',
        'calendar_uuid' => 'cal-1',
        'event_id' => 'evt-1',
        'start_at' => '2026-10-30 09:00:00',
        'end_at' => '2026-10-30 09:30:00',
        'attendee_email' => 'jan@example.com',
        'status' => BookingStatus::Confirmed,
        ...$overrides,
    ]);
}

function reconcileTestEvent(string $id, string $start, string $end): CalendarEvent
{
    return CalendarEvent::fromApi([
        'id' => $id,
        'start' => ['date_time' => $start, 'time_zone' => 'UTC'],
        'end' => ['date_time' => $end, 'time_zone' => 'UTC'],
    ]);
}

/**
 * @param  list<CalendarEvent>  $events
 */
function reconcileTestRun(array $events, string $calendar = 'cal-1'): void
{
    resolve(ReconcileBookings::class)->handle(
        $calendar,
        CarbonImmutable::parse('2026-10-26T12:00:00Z'),
        CarbonImmutable::parse('2026-11-25T12:00:00Z'),
        collect($events),
    );
}

it('leaves a present, unchanged booking alone', function (): void {
    $record = reconcileTestBooking();
    $updatedAt = $record->fresh()->updated_at;

    $this->travel(5)->minutes();
    reconcileTestRun([reconcileTestEvent('evt-1', '2026-10-30T09:00:00Z', '2026-10-30T09:30:00Z')]);

    expect($record->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($record->fresh()->missing_since)->toBeNull();
    Event::assertNotDispatched(BookingRescheduled::class);
    Event::assertNotDispatched(BookingCancelled::class);
});

it('stores new times and announces a rescheduled booking with the previous ones', function (): void {
    $record = reconcileTestBooking();

    reconcileTestRun([reconcileTestEvent('evt-1', '2026-10-31T10:00:00Z', '2026-10-31T10:45:00Z')]);

    $fresh = $record->fresh();

    expect($fresh->start_at->toIso8601ZuluString())->toBe('2026-10-31T10:00:00Z')
        ->and($fresh->end_at->toIso8601ZuluString())->toBe('2026-10-31T10:45:00Z')
        ->and($fresh->status)->toBe(BookingStatus::Confirmed);
    Event::assertDispatchedTimes(BookingRescheduled::class, 1);
    Event::assertDispatched(BookingRescheduled::class, fn (BookingRescheduled $event): bool => $event->booking->id === $record->id
        && $event->booking->start->toIso8601ZuluString() === '2026-10-31T10:00:00Z'
        && $event->booking->end->toIso8601ZuluString() === '2026-10-31T10:45:00Z'
        && $event->previousStart->toIso8601ZuluString() === '2026-10-30T09:00:00Z'
        && $event->previousEnd->toIso8601ZuluString() === '2026-10-30T09:30:00Z');
});

it('only moves the end when only the end changed, and clears a missing mark', function (): void {
    $record = reconcileTestBooking(['missing_since' => '2026-10-27 12:00:00']);

    reconcileTestRun([reconcileTestEvent('evt-1', '2026-10-30T09:00:00Z', '2026-10-30T10:00:00Z')]);

    expect($record->fresh()->end_at->toIso8601ZuluString())->toBe('2026-10-30T10:00:00Z')
        ->and($record->fresh()->missing_since)->toBeNull();
    Event::assertDispatchedTimes(BookingRescheduled::class, 1);
});

it('marks a booking whose event is missing once, without cancelling it', function (): void {
    $record = reconcileTestBooking();

    reconcileTestRun([]);

    $fresh = $record->fresh();

    expect($fresh->missing_since?->toIso8601ZuluString())->toBe('2026-10-28T12:00:00Z')
        ->and($fresh->status)->toBe(BookingStatus::Confirmed)
        ->and($fresh->cancelled_by)->toBeNull();
    Event::assertNotDispatched(BookingCancelled::class);
});

it('forgets the mark when the event is back on the next refresh', function (): void {
    $record = reconcileTestBooking();

    reconcileTestRun([]);
    reconcileTestRun([reconcileTestEvent('evt-1', '2026-10-30T09:00:00Z', '2026-10-30T09:30:00Z')]);
    reconcileTestRun([]);

    expect($record->fresh()->missing_since)->not->toBeNull()
        ->and($record->fresh()->status)->toBe(BookingStatus::Confirmed);
    Event::assertNotDispatched(BookingCancelled::class);
    Event::assertNotDispatched(BookingRescheduled::class);
});

it('cancels a booking externally when its event is missing in two refreshes in a row', function (): void {
    $record = reconcileTestBooking();

    reconcileTestRun([]);
    $this->travel(10)->minutes();
    reconcileTestRun([]);

    $fresh = $record->fresh();

    expect($fresh->status)->toBe(BookingStatus::Cancelled)
        ->and($fresh->cancelled_by)->toBe(CancelledBy::External)
        ->and($fresh->cancelled_at?->toIso8601ZuluString())->toBe('2026-10-28T12:10:00Z')
        ->and($fresh->missing_since)->toBeNull();
    Event::assertDispatchedTimes(BookingCancelled::class, 1);
    Event::assertDispatched(BookingCancelled::class, fn (BookingCancelled $event): bool => $event->booking->id === $record->id
        && $event->booking->status === BookingStatus::Cancelled
        && $event->cancelledBy === CancelledBy::External);
});

it('does not touch bookings outside the window, cancelled ones or those of another calendar', function (): void {
    $before = reconcileTestBooking(['event_id' => 'before', 'start_at' => '2026-10-26 11:00:00', 'end_at' => '2026-10-26 13:00:00']);
    $after = reconcileTestBooking(['event_id' => 'after', 'start_at' => '2026-11-25 11:00:00', 'end_at' => '2026-11-25 13:00:00']);
    $cancelled = reconcileTestBooking(['event_id' => 'cancelled', 'status' => BookingStatus::Cancelled, 'cancelled_by' => CancelledBy::User, 'cancelled_at' => '2026-10-27 12:00:00']);
    $other = reconcileTestBooking(['event_id' => 'other', 'calendar_uuid' => 'cal-2']);
    $marked = reconcileTestBooking(['event_id' => 'marked', 'start_at' => '2026-12-01 09:00:00', 'end_at' => '2026-12-01 09:30:00', 'missing_since' => '2026-10-27 12:00:00']);

    reconcileTestRun([]);
    reconcileTestRun([]);

    foreach ([$before, $after, $cancelled, $other] as $record) {
        expect($record->fresh()->missing_since)->toBeNull();
    }

    expect($before->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($after->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($cancelled->fresh()->cancelled_by)->toBe(CancelledBy::User)
        ->and($other->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($marked->fresh()->missing_since?->toIso8601ZuluString())->toBe('2026-10-27T12:00:00Z')
        ->and($marked->fresh()->status)->toBe(BookingStatus::Confirmed);
    Event::assertNotDispatched(BookingCancelled::class);
});

it('compares the window in UTC whatever zone the app and the window use', function (): void {
    $default = date_default_timezone_get();
    config()->set('app.timezone', 'Europe/Amsterdam');
    date_default_timezone_set('Europe/Amsterdam');

    try {
        $inside = reconcileTestBooking(['event_id' => 'inside', 'start_at' => '2026-10-26 12:30:00', 'end_at' => '2026-10-26 13:00:00']);
        $late = reconcileTestBooking(['event_id' => 'late', 'start_at' => '2026-11-25 12:15:00', 'end_at' => '2026-11-25 12:45:00']);

        resolve(ReconcileBookings::class)->handle(
            'cal-1',
            CarbonImmutable::parse('2026-10-26T14:00:00+02:00'),
            CarbonImmutable::parse('2026-11-25T13:00:00+01:00'),
            collect(),
        );

        expect($inside->fresh()->missing_since)->not->toBeNull()
            ->and($late->fresh()->missing_since)->toBeNull();
    } finally {
        date_default_timezone_set($default);
    }
});
