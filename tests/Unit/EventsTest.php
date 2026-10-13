<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Data\CirruxFailure;
use Codesmiths\CirruxCalendar\Data\Slot;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Events\BookingCancelled;
use Codesmiths\CirruxCalendar\Events\BookingCreated;
use Codesmiths\CirruxCalendar\Events\BookingCreating;
use Codesmiths\CirruxCalendar\Events\BookingRejected;
use Codesmiths\CirruxCalendar\Events\BookingRescheduled;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshed;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshFailed;
use Codesmiths\CirruxCalendar\Events\CalendarStale;
use Codesmiths\CirruxCalendar\Events\LoggedIn;
use Codesmiths\CirruxCalendar\Events\LoggedOut;
use Codesmiths\CirruxCalendar\Events\RateLimited;
use Codesmiths\CirruxCalendar\Events\TokenRefreshed;
use Codesmiths\CirruxCalendar\Events\TokenRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;

/**
 * An exception that really was thrown, from inside a closure: its stack trace holds
 * closures, so PHP cannot serialize it.
 */
function thrownFromClosure(CirruxException $exception): CirruxException
{
    try {
        // The closure is an argument of the frame that calls it, so it ends up in the trace.
        (static function (Closure $callback): void {
            $callback();
        })(static function () use ($exception): never {
            throw $exception;
        });
    } catch (CirruxException $cirruxException) {
        return $cirruxException;
    }
}

it('dispatches the auth events through the dispatchable trait', function (): void {
    Event::fake();
    $expiresAt = CarbonImmutable::now('UTC')->addHour();
    $failure = CirruxFailure::from(new CirruxAuthException('Session expired.'));

    event(new LoggedIn($expiresAt));
    event(new LoggedOut());
    event(new TokenRefreshed($expiresAt));
    event(new TokenRefreshFailed($failure));

    Event::assertDispatched(LoggedIn::class, fn (LoggedIn $event): bool => $event->expiresAt === $expiresAt);
    Event::assertDispatched(LoggedOut::class);
    Event::assertDispatched(TokenRefreshed::class, fn (TokenRefreshed $event): bool => $event->expiresAt === $expiresAt);
    Event::assertDispatched(TokenRefreshFailed::class, fn (TokenRefreshFailed $event): bool => $event->failure === $failure);
});

it('serializes the payloads for queued listeners', function (): void {
    $expiresAt = CarbonImmutable::now('UTC')->addHour();

    $loggedIn = unserialize(serialize(new LoggedIn($expiresAt)));
    $loggedOut = unserialize(serialize(new LoggedOut));

    expect($loggedIn)->toBeInstanceOf(LoggedIn::class)
        ->and($loggedIn->expiresAt->equalTo($expiresAt))->toBeTrue()
        ->and($loggedOut)->toBeInstanceOf(LoggedOut::class);
});

it('dispatches the cache events through the dispatchable trait', function (): void {
    Event::fake();
    $from = CarbonImmutable::parse('2026-10-26T00:00:00Z');
    $to = CarbonImmutable::parse('2026-11-23T00:00:00Z');
    $failure = CirruxFailure::from(new CirruxRequestException('Boom.', 500));

    event(new CalendarRefreshed('cal-1', $from, $to, 12, 2));
    event(new CalendarRefreshFailed('cal-1', $failure));
    event(new RateLimited(42));

    Event::assertDispatched(CalendarRefreshed::class, fn (CalendarRefreshed $event): bool => $event->calendarUuid === 'cal-1'
        && $event->from === $from
        && $event->to === $to
        && $event->eventCount === 12
        && $event->apiCalls === 2);
    Event::assertDispatched(CalendarRefreshFailed::class, fn (CalendarRefreshFailed $event): bool => $event->calendarUuid === 'cal-1' && $event->failure === $failure);
    Event::assertDispatched(RateLimited::class, fn (RateLimited $event): bool => $event->retryAfter === 42);
});

it('serializes the cache event payloads for queued listeners', function (): void {
    $from = CarbonImmutable::parse('2026-10-26T00:00:00Z');
    $to = CarbonImmutable::parse('2026-11-23T00:00:00Z');

    // Keep call arguments in stack traces, as a development setup does; with
    // zend.exception_ignore_args on (production default) the closure would not be in the trace.
    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

    try {
        $refreshed = unserialize(serialize(new CalendarRefreshed('cal-1', $from, $to, 12, 2)));
        $calendarFailed = unserialize(serialize(new CalendarRefreshFailed('cal-1', CirruxFailure::from(thrownFromClosure(new RateLimitedException(42))))));
        $tokenFailed = unserialize(serialize(new TokenRefreshFailed(CirruxFailure::from(thrownFromClosure(new CirruxAuthException('Cirrux rejected the refresh token (HTTP 401).'))))));
        $limited = unserialize(serialize(new RateLimited(42)));

        // The reason the events carry a CirruxFailure: the exception itself cannot be serialized.
        expect(fn (): string => serialize(thrownFromClosure(new CirruxAuthException('Nope.'))))->toThrow(Exception::class, 'Closure');
    } finally {
        ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
    }

    expect($refreshed)->toBeInstanceOf(CalendarRefreshed::class)
        ->and($refreshed->from->equalTo($from))->toBeTrue()
        ->and($refreshed->to->equalTo($to))->toBeTrue()
        ->and($refreshed->eventCount)->toBe(12)
        ->and($refreshed->apiCalls)->toBe(2)
        ->and($calendarFailed)->toBeInstanceOf(CalendarRefreshFailed::class)
        ->and($calendarFailed->calendarUuid)->toBe('cal-1')
        ->and($calendarFailed->failure)->toEqual(new CirruxFailure(RateLimitedException::class, 'Cirrux rate limit reached; retry in 42 seconds.', 0, 42))
        ->and($tokenFailed)->toBeInstanceOf(TokenRefreshFailed::class)
        ->and($tokenFailed->failure)->toEqual(new CirruxFailure(CirruxAuthException::class, 'Cirrux rejected the refresh token (HTTP 401).', 0))
        ->and($limited)->toBeInstanceOf(RateLimited::class)
        ->and($limited->retryAfter)->toBe(42);
});

it('dispatches and serializes CalendarStale, with or without a refresh time', function (): void {
    $refreshedAt = CarbonImmutable::parse('2026-10-28T10:00:00Z');

    Event::fake();
    event(new CalendarStale('cal-1', $refreshedAt));
    Event::assertDispatched(CalendarStale::class, fn (CalendarStale $event): bool => $event->calendarUuid === 'cal-1' && $event->refreshedAt === $refreshedAt);

    $stale = unserialize(serialize(new CalendarStale('cal-1', $refreshedAt)));
    $never = unserialize(serialize(new CalendarStale('cal-2', null)));

    expect($stale)->toBeInstanceOf(CalendarStale::class)
        ->and($stale->calendarUuid)->toBe('cal-1')
        ->and($stale->refreshedAt?->equalTo($refreshedAt))->toBeTrue()
        ->and($never)->toBeInstanceOf(CalendarStale::class)
        ->and($never->calendarUuid)->toBe('cal-2')
        ->and($never->refreshedAt)->toBeNull();
});

function eventsTestBooking(): Booking
{
    return new Booking(
        'bk-1',
        'cal-1',
        'evt-1',
        'demo',
        CarbonImmutable::parse('2026-10-20T08:00:00Z'),
        CarbonImmutable::parse('2026-10-20T08:30:00Z'),
        new Attendee('jan@example.com', 'Jan'),
        null,
        BookingStatus::Cancelled,
        CancelledBy::External,
        CarbonImmutable::parse('2026-10-19T08:00:00Z'),
    );
}

it('dispatches and serializes the booking events', function (): void {
    $booking = eventsTestBooking();
    $attendee = new Attendee('jan@example.com', 'Jan');
    $slot = new Slot($booking->start, $booking->end);

    $events = [
        new BookingCreating('demo', $slot, $attendee, 'Hi'),
        new BookingCreated($booking),
        new BookingRejected('demo', $booking->start, $attendee, 'busy'),
        new BookingCancelled($booking, CancelledBy::External),
        new BookingRescheduled($booking, $booking->start->subDay(), $booking->end->subDay()),
    ];

    Event::fake();

    foreach ($events as $event) {
        event($event);
        Event::assertDispatched($event::class);
    }

    foreach ($events as $event) {
        expect(unserialize(serialize($event)))->toEqual($event);
    }
});

it('dispatches only the committed booking events after commit', function (): void {
    expect(new BookingCreated(eventsTestBooking()))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new BookingCancelled(eventsTestBooking(), CancelledBy::User))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new BookingRescheduled(eventsTestBooking(), CarbonImmutable::now(), CarbonImmutable::now()))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new BookingCreating('demo', new Slot(CarbonImmutable::now(), CarbonImmutable::now()), new Attendee('a@b.nl'), null))->not->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new BookingRejected('demo', CarbonImmutable::now(), new Attendee('a@b.nl'), 'vetoed'))->not->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('keeps tokens out of the booking event payloads', function (): void {
    $serialized = serialize(new BookingCreated(eventsTestBooking()));

    expect($serialized)->not->toContain('token')->not->toContain('Token');
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
