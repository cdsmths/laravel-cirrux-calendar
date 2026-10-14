<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\CalendarResource;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Calendars\EventQuery;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\StaleCalendarException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T09:00:00Z'));
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

function slot(string $id, string $start, string $end): array
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

function windowQuery(): EventQuery
{
    return cachedWindowQuery()->fresh();
}

function cachedWindowQuery(): EventQuery
{
    return Cirrux::calendar('cal-1')->events()->between(
        CarbonImmutable::parse('2026-10-20T10:00:00Z'),
        CarbonImmutable::parse('2026-10-20T12:00:00Z'),
    );
}

function fillQueryCache(array $events = [], string $calendar = 'cal-1'): void
{
    resolve(EventCache::class)->replace(
        $calendar,
        CarbonImmutable::parse('2026-10-19T00:00:00Z'),
        CarbonImmutable::parse('2026-11-02T00:00:00Z'),
        collect($events)->map(static fn (array $event): CalendarEvent => CalendarEvent::fromApi($event)),
        CarbonImmutable::now('UTC'),
    );
}

it('resolves a calendar resource and its event query', function (): void {
    $calendar = Cirrux::calendar('cal-1');

    expect($calendar)->toBeInstanceOf(CalendarResource::class)
        ->and($calendar->uuid)->toBe('cal-1')
        ->and($calendar->events())->toBeInstanceOf(EventQuery::class);
});

it('reads all pages, drops duplicates and sends the window in UTC', function (): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [
            slot('a', '2026-10-20T10:00:00Z', '2026-10-20T10:30:00Z'),
            slot('b', '2026-10-20T11:00:00Z', '2026-10-20T11:30:00Z'),
        ]]),
        MockResponse::make(['has_more' => false, 'data' => [
            slot('b', '2026-10-20T11:00:00Z', '2026-10-20T11:30:00Z'),
            slot('c', '2026-10-20T11:30:00Z', '2026-10-20T11:45:00Z'),
        ]]),
    ]);

    $events = windowQuery()->get();

    expect($events)->toBeInstanceOf(Collection::class)
        ->and($events->pluck('id')->all())->toBe(['a', 'b', 'c'])
        ->and($events->keys()->all())->toBe([0, 1, 2])
        ->and($events->first())->toBeInstanceOf(CalendarEvent::class);

    Saloon::assertSentCount(2);

    $recorded = Saloon::mockClient()->getRecordedResponses();
    $query = array_map(static fn (Response $response): array => $response->getPendingRequest()->query()->all(), $recorded);

    expect($query[0])->toBe([
        'time_min' => '2026-10-20T10:00:00Z',
        'time_max' => '2026-10-20T12:00:00Z',
        'timezone' => 'UTC',
    ])
        ->and($query[1]['cursor'])->toBe('c1')
        ->and($query[1]['timezone'])->toBe('UTC');
});

it('keeps only events that overlap the window', function (): void {
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => [
        slot('before', '2026-10-20T08:00:00Z', '2026-10-20T09:00:00Z'),
        slot('ends-at-from', '2026-10-20T09:00:00Z', '2026-10-20T10:00:00Z'),
        slot('runs-into', '2026-10-20T09:30:00Z', '2026-10-20T10:30:00Z'),
        slot('inside', '2026-10-20T10:30:00Z', '2026-10-20T11:00:00Z'),
        slot('runs-out', '2026-10-20T11:30:00Z', '2026-10-20T12:30:00Z'),
        slot('spans', '2026-10-20T09:00:00Z', '2026-10-20T13:00:00Z'),
        slot('starts-at-to', '2026-10-20T12:00:00Z', '2026-10-20T12:30:00Z'),
        slot('after', '2026-10-20T13:00:00Z', '2026-10-20T14:00:00Z'),
    ]])]);

    expect(windowQuery()->get()->pluck('id')->all())->toBe(['runs-into', 'inside', 'runs-out', 'spans']);
});

it('refuses to read without a window', function (): void {
    Saloon::fake([]);

    expect(fn (): Collection => Cirrux::calendar('cal-1')->events()->get())
        ->toThrow(LogicException::class, 'between()');
    expect(fn (): Collection => Cirrux::calendar('cal-1')->events()->fresh()->get())
        ->toThrow(LogicException::class);
    Saloon::assertNothingSent();
});

it('fails the read when a page fails', function (): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => []]),
        MockResponse::make(['error' => 'boom'], 500),
    ]);

    expect(fn (): Collection => windowQuery()->get())->toThrow(CirruxRequestException::class);
});

it('never changes the query it was called on', function (): void {
    $base = Cirrux::calendar('cal-1')->events();
    $window = $base->between(CarbonImmutable::parse('2026-10-20T10:00:00Z'), CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    $fresh = $window->fresh();

    $later = $window->between(CarbonImmutable::parse('2026-10-21T10:00:00Z'), CarbonImmutable::parse('2026-10-21T12:00:00Z'));

    expect($window)->not->toBe($base)
        ->and($fresh)->not->toBe($window)
        ->and($later)->not->toBe($window)
        ->and(fn (): Collection => $base->get())->toThrow(LogicException::class);

    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    $window->fresh()->get();
    $later->fresh()->get();
    $fresh->get();

    $sent = array_map(
        static fn (Response $response): string => $response->getPendingRequest()->query()->all()['time_max'],
        Saloon::mockClient()->getRecordedResponses(),
    );

    expect($sent)->toBe(['2026-10-20T12:00:00Z', '2026-10-21T12:00:00Z', '2026-10-20T12:00:00Z']);
});

it('asks Cirrux for the window itself and filters on overlap locally', function (): void {
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => [
        slot('runs-into', '2026-10-20T09:00:00Z', '2026-10-20T10:30:00Z'),
        slot('ended-before', '2026-10-19T12:00:00Z', '2026-10-19T13:00:00Z'),
        slot('ends-at-from', '2026-10-20T08:00:00Z', '2026-10-20T10:00:00Z'),
        slot('inside', '2026-10-20T11:00:00Z', '2026-10-20T11:30:00Z'),
    ]])]);

    $events = windowQuery()->get();

    expect(Saloon::mockClient()->getRecordedResponses()[0]->getPendingRequest()->query()->all()['time_min'])->toBe('2026-10-20T10:00:00Z')
        ->and($events->pluck('id')->all())->toBe(['runs-into', 'inside']);
});

it('reads from the cache without calling Cirrux, sorted on start', function (): void {
    Saloon::fake([]);
    fillQueryCache([
        slot('late', '2026-10-20T11:00:00Z', '2026-10-20T11:30:00Z'),
        slot('outside', '2026-10-21T11:00:00Z', '2026-10-21T11:30:00Z'),
        slot('runs-into', '2026-10-20T09:30:00Z', '2026-10-20T10:30:00Z'),
    ]);

    $events = cachedWindowQuery()->get();

    expect($events->pluck('id')->all())->toBe(['runs-into', 'late'])
        ->and($events->first())->toBeInstanceOf(CalendarEvent::class);
    Saloon::assertSentCount(0);
});

it('reads an empty cached window as no events', function (): void {
    Saloon::fake([]);
    fillQueryCache();

    expect(cachedWindowQuery()->get()->all())->toBe([]);
    Saloon::assertSentCount(0);
});

it('fails closed when the cache is missing, stale or does not cover the window', function (Closure $arrange): void {
    Saloon::fake([]);
    $arrange();

    try {
        cachedWindowQuery()->get();
        $this->fail('Expected a StaleCalendarException.');
    } catch (StaleCalendarException $staleCalendarException) {
        expect($staleCalendarException->calendarUuid)->toBe('cal-1');
    }

    Saloon::assertSentCount(0);
})->with([
    'never refreshed' => [static fn (): null => null],
    'another calendar' => [static fn () => fillQueryCache(calendar: 'cal-2')],
    'older than max_staleness' => [function (): void {
        fillQueryCache();
        test()->travel(config()->integer('cirrux.cache.max_staleness') + 1)->seconds();
    }],
    'window beyond the coverage' => [function (): void {
        resolve(EventCache::class)->replace('cal-1', CarbonImmutable::parse('2026-10-19T00:00:00Z'), CarbonImmutable::parse('2026-10-20T00:00:00Z'), collect(), CarbonImmutable::now('UTC'));
    }],
]);

it('goes to Cirrux only after fresh', function (): void {
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => [slot('a', '2026-10-20T10:00:00Z', '2026-10-20T10:30:00Z')]])]);
    fillQueryCache();

    expect(cachedWindowQuery()->get()->all())->toBe([]);
    Saloon::assertSentCount(0);

    expect(cachedWindowQuery()->fresh()->get()->pluck('id')->all())->toBe(['a']);
    Saloon::assertSentCount(1);
});

it('accepts windows in any timezone', function (): void {
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    Cirrux::calendar('cal-1')->events()
        ->between(CarbonImmutable::parse('2026-10-20T12:00:00+02:00'), CarbonImmutable::parse('2026-10-20T14:00:00+02:00'))
        ->fresh()
        ->get();

    $query = Saloon::mockClient()->getRecordedResponses()[0]->getPendingRequest()->query()->all();

    expect($query['time_min'])->toBe('2026-10-20T10:00:00Z')
        ->and($query['time_max'])->toBe('2026-10-20T12:00:00Z');
});

it('creates an event through the query', function (): void {
    Event::fake([CalendarEventCreated::class]);
    Saloon::fake([CreateEvent::class => MockResponse::make(slot('evt-1', '2026-10-20T10:00:00Z', '2026-10-20T10:30:00Z'), 201)]);

    $event = Cirrux::calendar('cal-1')->events()->create(new NewEvent(
        title: 'Demo',
        start: CarbonImmutable::parse('2026-10-20T10:00:00Z')->startOfMinute(),
        end: CarbonImmutable::parse('2026-10-20T10:30:00Z')->startOfMinute(),
        timezone: 'UTC',
    ));

    expect($event->id)->toBe('evt-1');
    Event::assertDispatched(CalendarEventCreated::class, fn (CalendarEventCreated $created): bool => $created->calendarUuid === 'cal-1');
});

it('deletes an event through the query', function (): void {
    Event::fake([CalendarEventDeleted::class]);
    Saloon::fake([DeleteEvent::class => MockResponse::make('', 404)]);

    Cirrux::calendar('cal-1')->events()->delete('evt-1');

    Event::assertDispatched(CalendarEventDeleted::class, fn (CalendarEventDeleted $deleted): bool => $deleted->eventId === 'evt-1');
});
