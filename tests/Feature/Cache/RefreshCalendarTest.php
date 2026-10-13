<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshed;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshFailed;
use Codesmiths\CirruxCalendar\Events\RateLimited;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

// 2026-10-28 12:00 is a Wednesday; the refresh window is [2026-10-26 12:00, 2026-11-25 12:00): two days back, the default 4 weeks ahead.
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

function cirruxEventPayload(string $id, string $start, string $end): array
{
    return [
        'id' => $id,
        'event_uuid' => $id,
        'series_uuid' => null,
        'title' => 'Event '.$id,
        'start' => ['date_time' => $start, 'time_zone' => 'UTC'],
        'end' => ['date_time' => $end, 'time_zone' => 'UTC'],
        'attendees' => [],
    ];
}

function oldCache(): void
{
    resolve(EventCache::class)->replace(
        'cal-1',
        CarbonImmutable::parse('2026-10-26T12:00:00Z'),
        CarbonImmutable::parse('2026-11-25T12:00:00Z'),
        collect([CalendarEvent::fromApi(cirruxEventPayload('old', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z'))]),
        CarbonImmutable::now('UTC'),
    );
}

function cacheSnapshot(): array
{
    return Cache::many([
        'cirrux:events:cal-1:2026-W44',
        'cirrux:events:cal-1:2026-W45',
        'cirrux:events:cal-1:2026-W46',
        'cirrux:events:cal-1:2026-W47',
        'cirrux:events:cal-1:2026-W48',
        'cirrux:refreshed:cal-1',
    ]);
}

it('fetches every page, then replaces the weeks in one go and announces it', function (): void {
    Event::fake([CalendarRefreshed::class, CalendarRefreshFailed::class, RateLimited::class]);
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [
            cirruxEventPayload('a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z'),
            cirruxEventPayload('crossing', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z'),
            cirruxEventPayload('ended-before', '2026-10-25T10:00:00Z', '2026-10-25T11:00:00Z'),
        ]]),
        MockResponse::make(['has_more' => false, 'data' => [
            cirruxEventPayload('crossing', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z'),
            cirruxEventPayload('runs-into', '2026-10-26T11:00:00Z', '2026-10-26T13:00:00Z'),
            cirruxEventPayload('starts-at-to', '2026-11-25T12:00:00Z', '2026-11-25T13:00:00Z'),
        ]]),
    ]);

    resolve(RefreshCalendar::class)->handle('cal-1');

    $events = resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-26T12:00:00Z'), CarbonImmutable::parse('2026-11-25T12:00:00Z'));

    expect($events?->pluck('id')->all())->toBe(['runs-into', 'a', 'crossing'])
        ->and(Cache::get('cirrux:events:cal-1:2026-W46'))->toBe([])
        ->and(Cache::get('cirrux:events:cal-1:2026-W47'))->toBe([])
        ->and(Cache::get('cirrux:events:cal-1:2026-W48'))->toBe([])
        ->and(Cache::get('cirrux:events:cal-1:2026-W49'))->toBeNull()
        ->and(Cache::get('cirrux:refreshed:cal-1'))->toBe([
            'refreshed_at' => '2026-10-28T12:00:00+00:00',
            'from' => '2026-10-26T12:00:00+00:00',
            'to' => '2026-11-25T12:00:00+00:00',
        ]);

    Saloon::assertSentCount(2);
    Event::assertDispatchedTimes(CalendarRefreshed::class, 1);
    Event::assertDispatched(CalendarRefreshed::class, fn (CalendarRefreshed $event): bool => $event->calendarUuid === 'cal-1'
        && $event->from->toIso8601ZuluString() === '2026-10-26T12:00:00Z'
        && $event->to->toIso8601ZuluString() === '2026-11-25T12:00:00Z'
        && $event->eventCount === 3
        && $event->apiCalls === 2);
    Event::assertNotDispatched(CalendarRefreshFailed::class);
    Event::assertNotDispatched(RateLimited::class);

    $query = array_map(static fn (Response $response): array => $response->getPendingRequest()->query()->all(), Saloon::mockClient()->getRecordedResponses());

    expect($query[0])->toBe([
        'time_min' => '2026-10-25T12:00:00Z',
        'time_max' => '2026-11-25T12:00:00Z',
        'timezone' => 'UTC',
    ])->and($query[1]['cursor'])->toBe('c1');
});

it('stores a calendar without events as empty weeks', function (): void {
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    resolve(RefreshCalendar::class)->handle('cal-1');

    $events = resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-26T12:00:00Z'), CarbonImmutable::parse('2026-11-25T12:00:00Z'));

    expect($events?->all())->toBe([]);
});

it('leaves the old cache alone when a later page fails', function (): void {
    Event::fake([CalendarRefreshed::class, CalendarRefreshFailed::class, RateLimited::class]);
    oldCache();
    $before = cacheSnapshot();
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [cirruxEventPayload('new', '2026-10-29T10:00:00Z', '2026-10-29T11:00:00Z')]]),
        MockResponse::make(['error' => 'boom'], 500),
    ]);

    expect(fn () => resolve(RefreshCalendar::class)->handle('cal-1'))->toThrow(CirruxRequestException::class);

    expect(cacheSnapshot())->toBe($before);
    Event::assertDispatchedTimes(CalendarRefreshFailed::class, 1);
    Event::assertDispatched(CalendarRefreshFailed::class, fn (CalendarRefreshFailed $event): bool => $event->calendarUuid === 'cal-1'
        && $event->failure->exception === CirruxRequestException::class
        && $event->failure->status === 500
        && $event->failure->retryAfter === null);
    Event::assertNotDispatched(CalendarRefreshed::class);
    Event::assertNotDispatched(RateLimited::class);
});

it('also announces a rate limit and throws it again', function (): void {
    Event::fake([CalendarRefreshed::class, CalendarRefreshFailed::class, RateLimited::class]);
    oldCache();
    $before = cacheSnapshot();
    Saloon::fake([MockResponse::make([], 429, ['Retry-After' => '42'])]);

    expect(fn () => resolve(RefreshCalendar::class)->handle('cal-1'))->toThrow(RateLimitedException::class);

    expect(cacheSnapshot())->toBe($before);
    Event::assertDispatchedTimes(RateLimited::class, 1);
    Event::assertDispatched(RateLimited::class, fn (RateLimited $event): bool => $event->retryAfter === 42);
    Event::assertDispatched(CalendarRefreshFailed::class, fn (CalendarRefreshFailed $event): bool => $event->failure->exception === RateLimitedException::class
        && $event->failure->retryAfter === 42);
    Event::assertNotDispatched(CalendarRefreshed::class);
});

it('does not call Cirrux while another process holds the calendar lock', function (): void {
    Event::fake([CalendarRefreshed::class, CalendarRefreshFailed::class, RateLimited::class]);
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);
    $held = Cache::lock('cirrux:lock:cal-1', 30);
    $held->get();

    config()->set('cirrux.lock.timeout', 0);

    try {
        resolve(RefreshCalendar::class)->handle('cal-1');
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->getMessage())->toBe('Timed out waiting for another process to refresh the calendar.');
    }

    Saloon::assertSentCount(0);
    Event::assertDispatchedTimes(CalendarRefreshFailed::class, 1);
    Event::assertNotDispatched(CalendarRefreshed::class);

    // Another calendar has its own lock, and the first one is free again once released.
    resolve(RefreshCalendar::class)->handle('cal-2');
    $held->release();
    resolve(RefreshCalendar::class)->handle('cal-1');

    Saloon::assertSentCount(2);
});

it('releases the lock after a failure', function (): void {
    Saloon::fake([MockResponse::make(['error' => 'boom'], 500), MockResponse::make(['has_more' => false, 'data' => []])]);
    config()->set('cirrux.lock.timeout', 0);

    expect(fn () => resolve(RefreshCalendar::class)->handle('cal-1'))->toThrow(CirruxRequestException::class);

    resolve(RefreshCalendar::class)->handle('cal-1');

    Saloon::assertSentCount(2);
});
