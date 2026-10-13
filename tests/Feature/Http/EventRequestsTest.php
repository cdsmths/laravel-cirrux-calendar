<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Collection;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventPayload(string $id, array $overrides = []): array
{
    return [
        'object' => 'calendar_event',
        'id' => $id,
        'event_uuid' => $id,
        'series_uuid' => null,
        'title' => 'Event '.$id,
        'transparency' => null,
        'all_day' => false,
        'start' => ['date_time' => '2026-10-20T08:00:00Z', 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => '2026-10-20T08:30:00Z', 'time_zone' => 'Europe/Amsterdam'],
        'recurrence' => null,
        'attendees' => [],
        ...$overrides,
    ];
}

function listEvents(): ListEvents
{
    return new ListEvents(
        'cal-1',
        CarbonImmutable::parse('2026-10-19T22:00:00+00:00'),
        CarbonImmutable::parse('2026-10-26T23:00:00+01:00'),
        'Europe/Amsterdam',
    );
}

it('maps one page of events and sends the window as UTC instants', function (): void {
    Saloon::fake([
        ListEvents::class => MockResponse::make([
            'object' => 'list',
            'has_more' => false,
            'data' => [
                eventPayload('evt-1', ['transparency' => 'transparent']),
                eventPayload('series-1_20261027T080000Z', ['series_uuid' => 'series-1']),
                eventPayload('evt-3', ['all_day' => true, 'start' => ['date' => '2026-10-25'], 'end' => ['date' => '2026-10-26']]),
            ],
        ]),
    ]);

    $response = resolve(CirruxConnector::class)->send(listEvents());
    $events = $response->dto();
    $query = $response->getPendingRequest()->query()->all();

    expect($response->getPendingRequest()->getUri()->getPath())->toBe('/public_api/v1/calendars/cal-1/events')
        ->and($query)->toBe([
            'time_min' => '2026-10-19T22:00:00Z',
            'time_max' => '2026-10-26T22:00:00Z',
            'timezone' => 'Europe/Amsterdam',
        ])
        ->and($events)->toBeInstanceOf(Collection::class)
        ->and($events->keys()->all())->toBe([0, 1, 2])
        ->and($events->pluck('id')->all())->toBe(['evt-1', 'series-1_20261027T080000Z', 'evt-3'])
        ->and($events[1]->seriesId)->toBe('series-1')
        ->and($events[2]->allDay)->toBeTrue();
});

it('follows the cursor over several pages', function (): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-1'), eventPayload('evt-2')]]),
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c2', 'data' => [eventPayload('evt-3')]]),
        MockResponse::make(['has_more' => false, 'data' => [eventPayload('evt-4')]]),
    ]);

    $paginator = resolve(CirruxConnector::class)->paginate(listEvents());
    $paginator->setPerPageLimit(2);

    $events = $paginator->collect()->all();

    expect(array_map(static fn (CalendarEvent $event): string => $event->id, $events))->toBe(['evt-1', 'evt-2', 'evt-3', 'evt-4'])
        ->and($paginator->getTotalResults())->toBe(4);

    Saloon::assertSentCount(3);

    $queries = array_map(
        static fn (Response $response): array => $response->getPendingRequest()->query()->all(),
        Saloon::mockClient()->getRecordedResponses(),
    );

    expect($queries[0])->not->toHaveKey('cursor')
        ->and($queries[0]['limit'])->toBe(2)
        ->and($queries[1]['cursor'])->toBe('c1')
        ->and($queries[2]['cursor'])->toBe('c2')
        ->and($queries[2]['time_min'])->toBe('2026-10-19T22:00:00Z');
});

it('sends no limit unless one is set', function (): void {
    Saloon::fake([MockResponse::make(['has_more' => false, 'data' => []])]);

    $paginator = resolve(CirruxConnector::class)->paginate(listEvents());

    expect($paginator->collect()->all())->toBe([]);

    expect(Saloon::mockClient()->getRecordedResponses()[0]->getPendingRequest()->query()->all())->not->toHaveKey('limit');
});

it('fails the whole pagination when a later page fails', function (): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-1')]]),
        MockResponse::make(['error' => 'boom'], 500),
    ]);

    $paginator = resolve(CirruxConnector::class)->paginate(listEvents());

    expect(fn (): array => $paginator->collect()->all())->toThrow(CirruxRequestException::class);
});

it('refuses to follow a missing, empty or non-string cursor', function (array $page): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-1')]]),
        MockResponse::make([...$page, 'has_more' => true, 'data' => [eventPayload('evt-2')]]),
        MockResponse::make(['has_more' => false, 'data' => [eventPayload('evt-3')]]),
    ]);

    $paginator = resolve(CirruxConnector::class)->paginate(listEvents());

    expect(fn (): array => $paginator->collect()->all())->toThrow(CirruxRequestException::class, 'no next_cursor');

    Saloon::assertSentCount(2);
})->with([
    'missing' => [[]],
    'empty' => [['next_cursor' => '']],
    'null' => [['next_cursor' => null]],
    'number' => [['next_cursor' => 5]],
]);

it('refuses a cursor that was already used', function (): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-1')]]),
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c2', 'data' => [eventPayload('evt-2')]]),
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-3')]]),
        MockResponse::make(['has_more' => false, 'data' => [eventPayload('evt-4')]]),
    ]);

    $paginator = resolve(CirruxConnector::class)->paginate(listEvents());

    try {
        $paginator->collect()->all();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->getMessage())->toContain('"c1" twice')
            ->and($cirruxRequestException->status())->toBe(200);
    }

    Saloon::assertSentCount(3);
});

it('may iterate a paginator again from the start', function (): void {
    Saloon::fake([
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-1')]]),
        MockResponse::make(['has_more' => false, 'data' => [eventPayload('evt-2')]]),
        MockResponse::make(['has_more' => true, 'next_cursor' => 'c1', 'data' => [eventPayload('evt-1')]]),
        MockResponse::make(['has_more' => false, 'data' => [eventPayload('evt-2')]]),
    ]);

    $paginator = resolve(CirruxConnector::class)->paginate(listEvents());

    expect($paginator->collect()->count())->toBe(2)
        ->and($paginator->collect()->count())->toBe(2);
});

it('creates an event from the body of the new event and maps the answer', function (): void {
    Saloon::fake([
        CreateEvent::class => MockResponse::make(eventPayload('evt-9', [
            'attendees' => [['email' => 'jan@example.com', 'name' => 'Jan', 'status' => 'needs_action']],
        ]), 201),
    ]);

    $new = new NewEvent(
        title: 'Demo Trivle',
        start: CarbonImmutable::parse('2026-10-20T08:00:00Z'),
        end: CarbonImmutable::parse('2026-10-20T08:30:00Z'),
        timezone: 'Europe/Amsterdam',
        attendees: [new Attendee('jan@example.com', 'Jan')],
    );

    $response = resolve(CirruxConnector::class)->send(new CreateEvent('cal-1', $new));
    $event = $response->dto();
    $pending = $response->getPendingRequest();

    expect($pending->getMethod()->value)->toBe('POST')
        ->and($pending->getUri()->getPath())->toBe('/public_api/v1/calendars/cal-1/events')
        ->and($pending->body()?->all())->toBe($new->toBody())
        ->and($event)->toBeInstanceOf(CalendarEvent::class)
        ->and($event->id)->toBe('evt-9')
        ->and($event->attendees)->toEqual([new Attendee('jan@example.com', 'Jan')]);
});

it('deletes an event by occurrence id', function (): void {
    Saloon::fake([DeleteEvent::class => MockResponse::make('', 204)]);

    $response = resolve(CirruxConnector::class)->send(new DeleteEvent('cal-1', 'series-1_20261027T080000Z'));

    expect($response->status())->toBe(204)
        ->and($response->getPendingRequest()->getMethod()->value)->toBe('DELETE')
        ->and($response->getPendingRequest()->getUri()->getPath())->toBe('/public_api/v1/calendars/cal-1/events/series-1_20261027T080000Z');
});

it('sends writes only once, even on a 401 or a 5xx', function (Request $request, int $status): void {
    Saloon::fake([MockResponse::make(['error' => 'nope'], $status), MockResponse::make(eventPayload('evt-9'))]);

    expect($request->tries)->toBe(1)
        ->and(fn (): mixed => resolve(CirruxConnector::class)->send($request))->toThrow(CirruxRequestException::class);

    Saloon::assertSentCount(1);
})->with([
    'create 5xx' => [fn (): Request => new CreateEvent('cal-1', new NewEvent('Demo', CarbonImmutable::parse('2026-10-20T08:00:00Z'), CarbonImmutable::parse('2026-10-20T09:00:00Z'), 'UTC')), 503],
    'create 401' => [fn (): Request => new CreateEvent('cal-1', new NewEvent('Demo', CarbonImmutable::parse('2026-10-20T08:00:00Z'), CarbonImmutable::parse('2026-10-20T09:00:00Z'), 'UTC')), 401],
    'delete 5xx' => [fn (): Request => new DeleteEvent('cal-1', 'evt-1'), 503],
]);

it('surfaces a 404 on delete as a request exception', function (): void {
    Saloon::fake([DeleteEvent::class => MockResponse::make(['error' => 'not_found'], 404)]);

    try {
        resolve(CirruxConnector::class)->send(new DeleteEvent('cal-1', 'gone'));
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe(404);
    }
});

it('leaves the retry on a 401 to the connector for reads', function (): void {
    expect(listEvents()->tries)->toBeNull()
        ->and(resolve(CirruxConnector::class)->tries)->toBe(2);
});
