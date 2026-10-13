<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Actions\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshFailed;
use Codesmiths\CirruxCalendar\Events\RateLimited;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent as CreateEventRequest;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent as DeleteEventRequest;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Facades\Cache;
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

function writeThroughEvent(string $id, string $start, string $end, ?string $seriesId = null, bool $recurring = false): CalendarEvent
{
    return new CalendarEvent($id, $seriesId, 'Event '.$id, CarbonImmutable::parse($start), CarbonImmutable::parse($end), false, Transparency::Opaque, [], $recurring);
}

/**
 * Fills the weeks 2026-W44 and 2026-W45 of cal-1 and returns the ids per bucket.
 *
 * @param  list<CalendarEvent>  $events
 */
function fillWriteThroughCache(array $events = []): void
{
    test()->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));

    resolve(EventCache::class)->replace('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'), collect($events), CarbonImmutable::now('UTC'));
}

/**
 * @return list<string>
 */
function writeThroughIds(string $week): array
{
    return array_column((array) Cache::get('cirrux:events:cal-1:'.$week), 'id');
}

it('writes a created event through to the buckets that exist', function (): void {
    fillWriteThroughCache();
    Saloon::fake([CreateEventRequest::class => MockResponse::make(createdPayload('evt-1'), 201)]);

    resolve(CreateEvent::class)->handle('cal-1', newEvent());

    // The payload of createdPayload() lies in 2026-W43, which the refresh window does not cover.
    expect(writeThroughIds('2026-W43'))->toBe([]);

    Saloon::fake([CreateEventRequest::class => MockResponse::make([...createdPayload('evt-2'), 'start' => ['date_time' => '2026-10-28T08:00:00Z', 'time_zone' => 'UTC'], 'end' => ['date_time' => '2026-10-28T08:30:00Z', 'time_zone' => 'UTC']], 201)]);

    resolve(CreateEvent::class)->handle('cal-1', newEvent());

    expect(writeThroughIds('2026-W44'))->toBe(['evt-2'])
        ->and(writeThroughIds('2026-W45'))->toBe([]);
});

it('does not create a bucket for a created event without cache metadata', function (): void {
    Saloon::fake([CreateEventRequest::class => MockResponse::make(createdPayload('evt-1'), 201)]);

    resolve(CreateEvent::class)->handle('cal-1', newEvent());

    expect(Cache::get('cirrux:events:cal-1:2026-W43'))->toBeNull()
        ->and(resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-19T00:00:00Z'), CarbonImmutable::parse('2026-10-26T00:00:00Z')))->toBeNull();
});

it('removes a deleted event from the buckets', function (int $status): void {
    fillWriteThroughCache([
        writeThroughEvent('gone', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z'),
        writeThroughEvent('stays', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z'),
    ]);
    Saloon::fake([DeleteEventRequest::class => MockResponse::make('', $status)]);

    resolve(DeleteEvent::class)->handle('cal-1', 'gone');

    expect(writeThroughIds('2026-W44'))->toBe(['stays'])
        ->and(writeThroughIds('2026-W45'))->toBe([]);
    Saloon::assertSentCount(1);
})->with([
    'ok' => 200,
    'already gone' => 404,
]);

it('removes an event that is not cached by its id from every covered bucket', function (): void {
    fillWriteThroughCache([writeThroughEvent('stays', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z')]);
    Saloon::fake([DeleteEventRequest::class => MockResponse::make('', 204)]);

    resolve(DeleteEvent::class)->handle('cal-1', 'unknown');

    expect(writeThroughIds('2026-W44'))->toBe(['stays']);
    Saloon::assertSentCount(1);
});

it('forgets and refreshes the calendar when a series is deleted', function (CalendarEvent $cached, string $eventId): void {
    fillWriteThroughCache([$cached, writeThroughEvent('other', '2026-10-29T10:00:00Z', '2026-10-29T11:00:00Z')]);
    Saloon::fake([
        DeleteEventRequest::class => MockResponse::make('', 200),
        ListEvents::class => MockResponse::make(['has_more' => false, 'data' => [
            [...createdPayload('other'), 'start' => ['date_time' => '2026-10-29T10:00:00Z', 'time_zone' => 'UTC'], 'end' => ['date_time' => '2026-10-29T11:00:00Z', 'time_zone' => 'UTC']],
        ]]),
    ]);

    resolve(DeleteEvent::class)->handle('cal-1', $eventId);

    $events = resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'));

    expect($events?->pluck('id')->all())->toBe(['other']);
    Saloon::assertSent(ListEvents::class);
    Saloon::assertSentCount(2);
})->with([
    'a recurring occurrence' => [fn (): CalendarEvent => writeThroughEvent('series-1_a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z', 'series-1', true), 'series-1_a'],
    'the series by its id' => [fn (): CalendarEvent => writeThroughEvent('series-1_a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z', 'series-1'), 'series-1'],
]);

it('stays forgotten and quiet when the refresh after a series delete fails', function (): void {
    Event::fake([CalendarEventDeleted::class, CalendarRefreshFailed::class]);
    fillWriteThroughCache([writeThroughEvent('series-1_a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z', 'series-1', true)]);
    Saloon::fake([
        DeleteEventRequest::class => MockResponse::make('', 200),
        ListEvents::class => MockResponse::make(['error' => 'boom'], 500),
    ]);

    resolve(DeleteEvent::class)->handle('cal-1', 'series-1_a');

    expect(resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z')))->toBeNull();
    Event::assertDispatched(CalendarRefreshFailed::class);
    Event::assertDispatched(CalendarEventDeleted::class);
});

it('announces a rate limit on create and delete and throws it again', function (): void {
    Event::fake([CalendarEventCreated::class, CalendarEventDeleted::class, RateLimited::class]);
    Saloon::fake([MockResponse::make([], 429, ['Retry-After' => '42']), MockResponse::make([], 429, ['Retry-After' => '42'])]);

    expect(fn (): CalendarEvent => resolve(CreateEvent::class)->handle('cal-1', newEvent()))->toThrow(RateLimitedException::class);
    expect(fn () => resolve(DeleteEvent::class)->handle('cal-1', 'evt-1'))->toThrow(RateLimitedException::class);

    Event::assertDispatchedTimes(RateLimited::class, 2);
    Event::assertDispatched(RateLimited::class, fn (RateLimited $event): bool => $event->retryAfter === 42);
    Event::assertNotDispatched(CalendarEventCreated::class);
    Event::assertNotDispatched(CalendarEventDeleted::class);
});

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
