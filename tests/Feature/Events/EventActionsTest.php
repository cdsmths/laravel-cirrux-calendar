<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Actions\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent as CreateEventRequest;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent as DeleteEventRequest;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
    Event::fake([CalendarEventCreated::class, CalendarEventDeleted::class]);
});

function createdPayload(string $id): array
{
    return [
        'id' => $id,
        'event_uuid' => $id,
        'series_uuid' => null,
        'title' => 'Demo',
        'start' => ['date_time' => '2026-10-20T08:00:00Z', 'time_zone' => 'UTC'],
        'end' => ['date_time' => '2026-10-20T08:30:00Z', 'time_zone' => 'UTC'],
        'attendees' => [],
    ];
}

function newEvent(): NewEvent
{
    return new NewEvent(
        title: 'Demo',
        start: CarbonImmutable::parse('2026-10-20T08:00:00Z')->startOfMinute(),
        end: CarbonImmutable::parse('2026-10-20T08:30:00Z')->startOfMinute(),
        timezone: 'UTC',
    );
}

it('creates an event and dispatches CalendarEventCreated', function (): void {
    Saloon::fake([CreateEventRequest::class => MockResponse::make(createdPayload('evt-1'), 201)]);

    $event = resolve(CreateEvent::class)->handle('cal-1', newEvent());

    expect($event)->toBeInstanceOf(CalendarEvent::class)
        ->and($event->id)->toBe('evt-1');
    Event::assertDispatchedTimes(CalendarEventCreated::class, 1);
    Event::assertDispatched(
        CalendarEventCreated::class,
        fn (CalendarEventCreated $dispatched): bool => $dispatched->calendarUuid === 'cal-1' && $dispatched->event === $event,
    );
});

it('does not dispatch when the create fails', function (): void {
    Saloon::fake([CreateEventRequest::class => MockResponse::make(['error' => 'boom'], 500)]);

    expect(fn (): CalendarEvent => resolve(CreateEvent::class)->handle('cal-1', newEvent()))->toThrow(CirruxRequestException::class);
    Event::assertNotDispatched(CalendarEventCreated::class);
});

it('deletes an event and dispatches CalendarEventDeleted', function (int $status): void {
    Saloon::fake([DeleteEventRequest::class => MockResponse::make('', $status)]);

    resolve(DeleteEvent::class)->handle('cal-1', 'evt-1');

    Event::assertDispatchedTimes(CalendarEventDeleted::class, 1);
    Event::assertDispatched(
        CalendarEventDeleted::class,
        fn (CalendarEventDeleted $dispatched): bool => $dispatched->calendarUuid === 'cal-1' && $dispatched->eventId === 'evt-1',
    );
})->with([
    'ok' => 200,
    'no content' => 204,
    'already gone' => 404,
]);

it('lets other delete failures through without dispatching', function (int $status): void {
    Saloon::fake([DeleteEventRequest::class => MockResponse::make(['error' => 'nope'], $status)]);

    try {
        resolve(DeleteEvent::class)->handle('cal-1', 'evt-1');
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe($status);
    }

    Event::assertNotDispatched(CalendarEventDeleted::class);
    Saloon::assertSentCount(1);
})->with([
    'server error' => 500,
    'forbidden' => 403,
]);
