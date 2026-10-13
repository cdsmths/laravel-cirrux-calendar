<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<string, mixed>  $overrides
 */
function bookingRecordRow(array $overrides = []): BookingRecord
{
    return BookingRecord::query()->create([
        'event_type' => 'demo',
        'calendar_uuid' => 'cal-1',
        'event_id' => 'evt-1',
        'start_at' => '2026-10-20 08:00:00',
        'end_at' => '2026-10-20 08:30:00',
        'attendee_email' => 'jan@example.com',
        'status' => BookingStatus::Confirmed,
        ...$overrides,
    ]);
}

it('creates the bookings table with its indexes', function (): void {
    expect(Schema::hasTable('cirrux_bookings'))->toBeTrue()
        ->and(Schema::hasColumns('cirrux_bookings', ['id', 'event_type', 'calendar_uuid', 'event_id', 'start_at', 'end_at', 'attendee_email', 'attendee_name', 'notes', 'status', 'missing_since', 'cancelled_at', 'cancelled_by', 'created_at', 'updated_at']))->toBeTrue();

    $indexes = collect(Schema::getIndexes('cirrux_bookings'));

    expect($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['calendar_uuid', 'event_id']))->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => ! $index['unique'] && $index['columns'] === ['calendar_uuid', 'status', 'start_at']))->toBeTrue();
});

it('maps a confirmed row with nullables to a booking', function (): void {
    $record = bookingRecordRow()->fresh();

    expect($record)->toBeInstanceOf(BookingRecord::class);

    $booking = $record->toBooking();

    expect($booking)->toEqual(new Booking(
        $record->id,
        'cal-1',
        'evt-1',
        'demo',
        CarbonImmutable::parse('2026-10-20T08:00:00Z'),
        CarbonImmutable::parse('2026-10-20T08:30:00Z'),
        new Attendee('jan@example.com'),
        null,
        BookingStatus::Confirmed,
        null,
        null,
    ))
        ->and($booking->start->timezoneName)->toBe('UTC')
        ->and($record->missing_since)->toBeNull();
});

it('maps a cancelled row with enums and UTC times to a booking', function (): void {
    $record = bookingRecordRow([
        'attendee_name' => 'Jan',
        'notes' => 'Bring a laptop',
        'status' => BookingStatus::Cancelled,
        'cancelled_by' => CancelledBy::External,
        'cancelled_at' => '2026-10-19 09:00:00',
        'missing_since' => '2026-10-19 08:00:00',
    ])->fresh();

    $booking = $record->toBooking();

    expect($booking->id)->toBe($record->id)
        ->and($booking->attendee)->toEqual(new Attendee('jan@example.com', 'Jan'))
        ->and($booking->notes)->toBe('Bring a laptop')
        ->and($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancelledBy)->toBe(CancelledBy::External)
        ->and($booking->cancelledAt?->toIso8601String())->toBe('2026-10-19T09:00:00+00:00')
        ->and($booking->cancelledAt->timezoneName)->toBe('UTC')
        ->and($record->missing_since?->toIso8601String())->toBe('2026-10-19T08:00:00+00:00');
});

it('keeps every instant and the stored value in UTC when the app runs in another timezone', function (): void {
    $previousDefault = date_default_timezone_get();
    $previousConfig = config('app.timezone');

    config()->set('app.timezone', 'Europe/Amsterdam');
    date_default_timezone_set('Europe/Amsterdam');

    try {
        $record = bookingRecordRow([
            'start_at' => CarbonImmutable::parse('2026-10-20T10:00:00', 'Europe/Amsterdam'),
            'end_at' => CarbonImmutable::parse('2026-10-20T10:30:00', 'Europe/Amsterdam'),
            'missing_since' => CarbonImmutable::parse('2026-10-19T11:00:00', 'Europe/Amsterdam'),
            'status' => BookingStatus::Cancelled,
            'cancelled_by' => CancelledBy::External,
            'cancelled_at' => CarbonImmutable::parse('2026-10-19T11:30:00', 'Europe/Amsterdam'),
        ]);

        $fresh = $record->fresh();
        $raw = (array) DB::table('cirrux_bookings')->where('id', $record->id)->first();

        expect($fresh->start_at->toIso8601ZuluString())->toBe('2026-10-20T08:00:00Z')
            ->and($fresh->end_at->toIso8601ZuluString())->toBe('2026-10-20T08:30:00Z')
            ->and($fresh->missing_since?->toIso8601ZuluString())->toBe('2026-10-19T09:00:00Z')
            ->and($fresh->cancelled_at?->toIso8601ZuluString())->toBe('2026-10-19T09:30:00Z')
            ->and($fresh->toBooking()->start->toIso8601ZuluString())->toBe('2026-10-20T08:00:00Z')
            ->and($raw['start_at'])->toBe('2026-10-20 08:00:00')
            ->and($raw['end_at'])->toBe('2026-10-20 08:30:00')
            ->and($raw['missing_since'])->toBe('2026-10-19 09:00:00')
            ->and($raw['cancelled_at'])->toBe('2026-10-19 09:30:00');

        // A binding in UTC finds the row; this is what the actions do.
        expect(BookingRecord::query()->where('start_at', '>=', CarbonImmutable::parse('2026-10-20T08:00:00Z'))->count())->toBe(1)
            ->and(BookingRecord::query()->where('start_at', '>', CarbonImmutable::parse('2026-10-20T08:00:00Z'))->count())->toBe(0);
    } finally {
        date_default_timezone_set($previousDefault);
        config()->set('app.timezone', $previousConfig);
    }
});

it('reads a missing or null column as null', function (): void {
    $record = bookingRecordRow(['missing_since' => null]);

    expect($record->missing_since)->toBeNull()
        ->and($record->fresh()->cancelled_at)->toBeNull();
});

it('generates a uuid as the primary key', function (): void {
    expect(bookingRecordRow()->id)->toBeUuid();
});

it('enforces a unique event per calendar', function (): void {
    bookingRecordRow();

    bookingRecordRow(['calendar_uuid' => 'cal-2']);

    expect(fn (): BookingRecord => bookingRecordRow())->toThrow(UniqueConstraintViolationException::class);
});
