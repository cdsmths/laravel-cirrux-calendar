<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Events\LoggedIn;
use Codesmiths\CirruxCalendar\Events\LoggedOut;
use Codesmiths\CirruxCalendar\Events\TokenRefreshed;
use Codesmiths\CirruxCalendar\Events\TokenRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Illuminate\Support\Facades\Event;

it('dispatches the auth events through the dispatchable trait', function (): void {
    Event::fake();
    $expiresAt = CarbonImmutable::now('UTC')->addHour();
    $exception = new CirruxAuthException('Session expired.');

    event(new LoggedIn($expiresAt));
    event(new LoggedOut());
    event(new TokenRefreshed($expiresAt));
    event(new TokenRefreshFailed($exception));

    Event::assertDispatched(LoggedIn::class, fn (LoggedIn $event): bool => $event->expiresAt === $expiresAt);
    Event::assertDispatched(LoggedOut::class);
    Event::assertDispatched(TokenRefreshed::class, fn (TokenRefreshed $event): bool => $event->expiresAt === $expiresAt);
    Event::assertDispatched(TokenRefreshFailed::class, fn (TokenRefreshFailed $event): bool => $event->exception === $exception);
});

it('serializes the payloads for queued listeners', function (): void {
    $expiresAt = CarbonImmutable::now('UTC')->addHour();

    $loggedIn = unserialize(serialize(new LoggedIn($expiresAt)));
    $loggedOut = unserialize(serialize(new LoggedOut));

    expect($loggedIn)->toBeInstanceOf(LoggedIn::class)
        ->and($loggedIn->expiresAt->equalTo($expiresAt))->toBeTrue()
        ->and($loggedOut)->toBeInstanceOf(LoggedOut::class);
});

it('serializes the calendar event payloads for queued listeners', function (): void {
    $calendarEvent = new CalendarEvent(
        'evt-1',
        null,
        'Demo',
        CarbonImmutable::parse('2026-10-20T08:00:00Z'),
        CarbonImmutable::parse('2026-10-20T08:30:00Z'),
        false,
        Transparency::Opaque,
        [new Attendee('jan@example.com', 'Jan')],
        false,
    );

    $created = unserialize(serialize(new CalendarEventCreated('cal-1', $calendarEvent)));
    $deleted = unserialize(serialize(new CalendarEventDeleted('cal-1', 'evt-1')));

    expect($created)->toBeInstanceOf(CalendarEventCreated::class)
        ->and($created->calendarUuid)->toBe('cal-1')
        ->and($created->event)->toEqual($calendarEvent)
        ->and($deleted)->toBeInstanceOf(CalendarEventDeleted::class)
        ->and($deleted->calendarUuid)->toBe('cal-1')
        ->and($deleted->eventId)->toBe('evt-1');
});
