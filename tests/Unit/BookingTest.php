<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CancelledBy;

it('turns a confirmed booking into an array', function (): void {
    $booking = new Booking(
        'bk-1',
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
    );

    expect($booking->toArray())->toBe([
        'id' => 'bk-1',
        'calendar_uuid' => 'cal-1',
        'event_id' => 'evt-1',
        'event_type' => 'demo',
        'start' => '2026-10-20T08:00:00+00:00',
        'end' => '2026-10-20T08:30:00+00:00',
        'attendee' => ['email' => 'jan@example.com', 'name' => null],
        'notes' => null,
        'status' => 'confirmed',
        'cancelled_by' => null,
        'cancelled_at' => null,
    ]);
});

it('normalizes the instants of a booking to UTC', function (): void {
    $booking = new Booking(
        'bk-1',
        'cal-1',
        'evt-1',
        'demo',
        CarbonImmutable::parse('2026-10-20T10:00:00+02:00'),
        CarbonImmutable::parse('2026-10-20T10:30:00+02:00'),
        new Attendee('jan@example.com'),
        null,
        BookingStatus::Cancelled,
        CancelledBy::User,
        CarbonImmutable::parse('2026-10-19T11:00:00', 'Europe/Amsterdam'),
    );

    expect($booking->start->timezoneName)->toBe('UTC')
        ->and($booking->start->toIso8601String())->toBe('2026-10-20T08:00:00+00:00')
        ->and($booking->end->toIso8601String())->toBe('2026-10-20T08:30:00+00:00')
        ->and($booking->cancelledAt?->toIso8601String())->toBe('2026-10-19T09:00:00+00:00')
        ->and($booking->toArray())->toMatchArray([
            'start' => '2026-10-20T08:00:00+00:00',
            'end' => '2026-10-20T08:30:00+00:00',
            'cancelled_at' => '2026-10-19T09:00:00+00:00',
        ]);
});

it('turns a cancelled booking into an array', function (): void {
    $booking = new Booking(
        'bk-1',
        'cal-1',
        'evt-1',
        'demo',
        CarbonImmutable::parse('2026-10-20T08:00:00Z'),
        CarbonImmutable::parse('2026-10-20T08:30:00Z'),
        new Attendee('jan@example.com', 'Jan'),
        'Notes',
        BookingStatus::Cancelled,
        CancelledBy::User,
        CarbonImmutable::parse('2026-10-19T09:00:00Z'),
    );

    expect($booking->toArray())
        ->toMatchArray(['status' => 'cancelled', 'cancelled_by' => 'user', 'cancelled_at' => '2026-10-19T09:00:00+00:00', 'notes' => 'Notes'])
        ->and($booking->toArray()['attendee'])->toBe(['email' => 'jan@example.com', 'name' => 'Jan']);
});
