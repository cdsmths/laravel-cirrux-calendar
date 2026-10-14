<?php

declare(strict_types=1);

/**
 * Contract tests against a real, empty Cirrux test calendar. Opt-in: `composer test:contract`
 * with CIRRUX_TEST_CALENDAR (calendar uuid) and CIRRUX_REFRESH_TOKEN. Without them every test
 * is skipped. Every event a test creates is titled "[contract-test] ..." and deleted afterwards.
 *
 * Each test pins one assumption of the package about the API, most of them documented in the
 * Cirrux API reference (cirrux.co/help/api/calendar). When a test fails, the assumption (or the
 * package code built on it) is wrong:
 *
 *  1. Window semantics: time_min/time_max match events that OVERLAP the window, not only
 *     events that start in it (documented; it was open point 3 of plan.md).
 *  2. `timezone` parameter: it never changes the instant a bound denotes, and `time_zone`
 *     stays the event's own zone.
 *  3. Series ids (the recurrence body being accepted is pinned apart, so a rejected body is not
 *     mistaken for a wrong id scheme): an expanded occurrence has the id `<series-uuid>_<recurrence-id>` and its
 *     `series_uuid` holds the uuid of the series. The recurrence is sent as an RRULE string
 *     (a guess from the CLI's `--recurrence`).
 *  4. The create response is a bare event, with the fields CalendarEvent::fromApi reads.
 *  5. Deleting is idempotent in effect: the second delete answers 404.
 *  6. Autumn DST change: events just before, inside and after the repeated hour come back as
 *     the instants that were sent, after UTC normalisation (NewEvent sends UTC instants).
 *  7. `transparent` is returned as `transparent`.
 *  8. Pagination: a small `limit` gives `has_more` and `next_cursor`, and the paginator
 *     follows them until every event is fetched, without duplicates.
 */

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Codesmiths\CirruxCalendar\Tests\Contract\ContractSession;

beforeEach(function (): void {
    ContractSession::begin();
});

afterEach(function (): void {
    ContractSession::finish();
});

it('matches events that overlap time_min/time_max, not only events that start inside the window', function (): void {
    $continuing = ContractSession::create(ContractSession::event('window: starts before time_min', ContractSession::at(0, 11), 120));
    $inside = ContractSession::create(ContractSession::event('window: starts inside', ContractSession::at(0, 13)));
    $earlier = ContractSession::create(ContractSession::event('window: ends before time_min', ContractSession::at(0, 8), 60));

    $ids = ContractSession::ids(ContractSession::list(ContractSession::at(0, 12), ContractSession::at(0, 14)));

    expect($ids)
        ->toContain($continuing->id)
        ->toContain($inside->id)
        ->not->toContain($earlier->id);
});

it('keeps the instant and the event time zone when the timezone parameter changes', function (): void {
    $created = ContractSession::create(ContractSession::event('timezone parameter', ContractSession::at(1, 12)));

    foreach (['UTC', 'Europe/Amsterdam', 'America/New_York'] as $zone) {
        $item = ContractSession::find(
            ContractSession::list(ContractSession::at(1, 11), ContractSession::at(1, 13), $zone),
            $created->id,
        );

        expect($item)->not->toBeNull();

        $event = CalendarEvent::fromApi($item ?? []);

        expect(data_get($item, 'start.time_zone'))->toBe(ContractSession::ZONE)
            ->and(data_get($item, 'end.time_zone'))->toBe(ContractSession::ZONE)
            ->and($event->start->toIso8601ZuluString())->toBe($created->start->toIso8601ZuluString())
            ->and($event->end->toIso8601ZuluString())->toBe($created->end->toIso8601ZuluString());
    }
});

it('accepts a create body with a recurrence', function (): void {
    try {
        $series = ContractSession::createSeries('series', ContractSession::at(5, 12));
    } catch (CirruxRequestException $cirruxRequestException) {
        $this->fail(sprintf('Cirrux rejected the recurrence body (HTTP %d): %s', $cirruxRequestException->status(), $cirruxRequestException->getMessage()));
    }

    expect($series['event_uuid'] ?? null)->toBeString();
});

it('gives an expanded occurrence the id <series-uuid>_<recurrence-id> and carries the series uuid', function (): void {
    $title = ContractSession::title('series');
    $series = ContractSession::createSeries('series', ContractSession::at(5, 12));

    $seriesUuid = $series['event_uuid'] ?? null;

    expect($seriesUuid)->toBeString();

    $occurrences = array_values(array_filter(
        ContractSession::items(ContractSession::list(ContractSession::at(4, 0), ContractSession::at(8, 0))),
        static fn (array $item): bool => ($item['title'] ?? null) === $title,
    ));

    expect($occurrences)->toHaveCount(3);

    $ids = [];

    foreach ($occurrences as $occurrence) {
        $event = CalendarEvent::fromApi($occurrence);
        $ids[] = $event->id;

        expect($occurrence['series_uuid'] ?? null)->toBe($seriesUuid)
            ->and($occurrence['recurrence_id'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($event->id)->toBe($seriesUuid.'_'.$occurrence['recurrence_id'])
            ->and($event->seriesId)->toBe($seriesUuid)
            ->and($event->isRecurring)->toBeTrue();
    }

    expect(array_unique($ids))->toHaveCount(3);
})->depends('it accepts a create body with a recurrence');

it('answers a create with a bare event that CalendarEvent::fromApi can read', function (): void {
    $start = ContractSession::at(2, 12);
    $newEvent = ContractSession::event('create response', $start);

    $response = ContractSession::connector()->send(new CreateEvent(ContractSession::calendar(), $newEvent));
    $body = $response->array();
    ContractSession::track($body);

    expect($body)->toHaveKeys(['id', 'event_uuid', 'title', 'start', 'end', 'transparency', 'attendees'])
        ->not->toHaveKey('data')
        ->and($body['id'])->toBe($body['event_uuid'])
        ->and($body['series_uuid'] ?? null)->toBeNull()
        ->and($body['title'])->toBe($newEvent->title)
        ->and(data_get($body, 'start.time_zone'))->toBeString()
        ->and(data_get($body, 'start.date_time'))->toBeString();

    $event = $response->dto();

    expect($event)->toBeInstanceOf(CalendarEvent::class);
    assert($event instanceof CalendarEvent);

    expect($event->id)->toBe($body['id'])
        ->and($event->title)->toBe($newEvent->title)
        ->and($event->start->toIso8601ZuluString())->toBe($start->toIso8601ZuluString())
        ->and($event->end->toIso8601ZuluString())->toBe($newEvent->end->toIso8601ZuluString())
        ->and($event->allDay)->toBeFalse()
        ->and($event->seriesId)->toBeNull()
        ->and($event->isRecurring)->toBeFalse()
        ->and($event->transparency)->toBe(Transparency::Opaque);
});

it('answers 404 when an event that was already deleted is deleted again', function (): void {
    $event = ContractSession::create(ContractSession::event('delete twice', ContractSession::at(3, 12)));

    $first = ContractSession::connector()->send(new DeleteEvent(ContractSession::calendar(), $event->id));

    expect($first->successful())->toBeTrue();

    $status = null;

    try {
        ContractSession::connector()->send(new DeleteEvent(ContractSession::calendar(), $event->id));
    } catch (CirruxRequestException $cirruxRequestException) {
        $status = $cirruxRequestException->status();
    }

    expect($status)->toBe(404);
});

it('returns the sent instants for events around and inside the repeated hour of the autumn DST change', function (): void {
    $change = ContractSession::nextAutumnChange();
    $sent = [
        'before the change' => $change->setTime(1, 0),
        // Real minutes after local midnight: the first 02:30 (summer time) and the second (winter time).
        'first 02:30' => $change->addMinutes(150),
        'second 02:30' => $change->addMinutes(210),
        'after the change' => $change->setTime(3, 0),
    ];

    $created = array_map(
        static fn (string $what, CarbonImmutable $start): CalendarEvent => ContractSession::create(ContractSession::event($what, $start)),
        array_keys($sent),
        $sent,
    );

    $page = ContractSession::list($change, $change->addDay());

    foreach (array_values($sent) as $index => $start) {
        $item = ContractSession::find($page, $created[$index]->id);

        expect($item)->not->toBeNull();

        $listed = CalendarEvent::fromApi($item ?? []);

        expect($created[$index]->start->toIso8601ZuluString())->toBe($start->utc()->toIso8601ZuluString())
            ->and($listed->start->toIso8601ZuluString())->toBe($start->utc()->toIso8601ZuluString())
            ->and($listed->end->toIso8601ZuluString())->toBe($start->addMinutes(30)->utc()->toIso8601ZuluString());
    }
});

it('returns transparency transparent as transparent', function (): void {
    $body = ContractSession::createRaw(ContractSession::event('transparency', ContractSession::at(6, 12), 30, Transparency::Transparent));

    expect($body['transparency'] ?? null)->toBe('transparent');

    $item = ContractSession::find(
        ContractSession::list(ContractSession::at(6, 11), ContractSession::at(6, 13)),
        CalendarEvent::fromApi($body)->id,
    );

    expect($item)->not->toBeNull()
        ->and($item['transparency'] ?? null)->toBe('transparent')
        ->and(CalendarEvent::fromApi($item ?? [])->transparency)->toBe(Transparency::Transparent);
});

it('reports has_more and next_cursor for a small limit and lets the paginator fetch everything', function (): void {
    $first = ContractSession::create(ContractSession::event('pagination 1', ContractSession::at(10, 12)));
    $second = ContractSession::create(ContractSession::event('pagination 2', ContractSession::at(10, 13)));

    $from = ContractSession::at(10, 0);
    $to = ContractSession::at(11, 0);

    $page = ContractSession::list($from, $to, ContractSession::ZONE, ['limit' => 1]);

    expect(ContractSession::items($page))->toHaveCount(1)
        ->and($page['has_more'] ?? null)->toBeTrue()
        ->and($page['next_cursor'] ?? null)->toBeString()->not->toBeEmpty();

    $cursor = $page['next_cursor'] ?? '';
    $next = ContractSession::list($from, $to, ContractSession::ZONE, ['limit' => 1, 'cursor' => is_string($cursor) ? $cursor : '']);

    expect(ContractSession::ids($next))->not->toContain(...ContractSession::ids($page));

    $fetched = ContractSession::connector()
        ->paginate(new ListEvents(ContractSession::calendar(), $from, $to, ContractSession::ZONE))
        ->setPerPageLimit(1)
        ->collect()
        ->map(static fn (CalendarEvent $event): string => $event->id)
        ->values()
        ->all();

    expect($fetched)
        ->toContain($first->id)
        ->toContain($second->id)
        ->and(array_unique($fetched))->toHaveCount(count($fetched));
});
