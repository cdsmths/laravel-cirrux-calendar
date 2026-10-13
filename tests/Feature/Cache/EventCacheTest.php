<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Cache\ForgetCalendar;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// 2026-10-28 is a Wednesday in ISO week 2026-W44, which starts on Monday 2026-10-26.
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
});

function cachedEvent(string $id, string $start, string $end, ?string $title = null): CalendarEvent
{
    return new CalendarEvent(
        $id,
        null,
        $title ?? 'Event '.$id,
        CarbonImmutable::parse($start),
        CarbonImmutable::parse($end),
        false,
        Transparency::Opaque,
        [new Attendee('jan@example.com', 'Jan')],
        false,
    );
}

function weekBucket(string $week, string $calendar = 'cal-1'): mixed
{
    return Cache::get(sprintf('cirrux:events:%s:%s', $calendar, $week));
}

/**
 * @return list<string>
 */
function bucketIds(string $week): array
{
    return array_map(static fn (array $row): string => $row['id'], (array) weekBucket($week));
}

function fillWeeks(string $from = '2026-10-26T00:00:00Z', string $to = '2026-11-09T00:00:00Z', array $events = []): EventCache
{
    $cache = resolve(EventCache::class);
    $cache->replace('cal-1', CarbonImmutable::parse($from), CarbonImmutable::parse($to), collect($events), CarbonImmutable::now('UTC'));

    return $cache;
}

it('puts an event that crosses a week boundary and a multi-day event in every week they touch', function (): void {
    $crossing = cachedEvent('crossing', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z');
    $multiDay = cachedEvent('multi', '2026-10-27T10:00:00Z', '2026-11-03T10:00:00Z');
    $single = cachedEvent('single', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z');

    fillWeeks(events: [$crossing, $multiDay, $single]);

    expect(bucketIds('2026-W44'))->toBe(['crossing', 'multi', 'single'])
        ->and(bucketIds('2026-W45'))->toBe(['crossing', 'multi']);
});

it('puts an event that ends exactly on Monday midnight only in the earlier week', function (): void {
    fillWeeks(events: [cachedEvent('ends-monday', '2026-11-01T22:00:00Z', '2026-11-02T00:00:00Z')]);

    expect(bucketIds('2026-W44'))->toBe(['ends-monday'])
        ->and(bucketIds('2026-W45'))->toBe([]);
});

it('reads every event once, sorted on start, filtered on overlap with the window', function (): void {
    fillWeeks(events: [
        cachedEvent('late', '2026-11-03T10:00:00Z', '2026-11-03T11:00:00Z'),
        cachedEvent('crossing', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z'),
        cachedEvent('early', '2026-10-27T10:00:00Z', '2026-10-27T11:00:00Z'),
    ]);

    $all = resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'));
    $window = resolve(EventCache::class)->read('cal-1', CarbonImmutable::parse('2026-11-01T23:30:00Z'), CarbonImmutable::parse('2026-11-03T10:00:00Z'));

    expect($all)->toBeInstanceOf(Collection::class)
        ->and($all?->pluck('id')->all())->toBe(['early', 'crossing', 'late'])
        ->and($all?->keys()->all())->toBe([0, 1, 2])
        ->and($window?->pluck('id')->all())->toBe(['crossing']);
});

it('keeps "no events" apart from "no data"', function (): void {
    fillWeeks();

    $cache = resolve(EventCache::class);
    $empty = $cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'));

    expect(weekBucket('2026-W44'))->toBe([])
        ->and(weekBucket('2026-W45'))->toBe([])
        ->and($empty)->toBeInstanceOf(Collection::class)
        ->and($empty?->all())->toBe([])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-16T00:00:00Z')))->toBeNull();
});

it('reads null when a bucket is missing or damaged', function (): void {
    $cache = fillWeeks();
    $from = CarbonImmutable::parse('2026-10-26T00:00:00Z');
    $to = CarbonImmutable::parse('2026-11-09T00:00:00Z');

    Cache::forget('cirrux:events:cal-1:2026-W45');
    expect($cache->read('cal-1', $from, $to))->toBeNull();

    Cache::put('cirrux:events:cal-1:2026-W45', 'garbage');
    expect($cache->read('cal-1', $from, $to))->toBeNull();
});

it('reads null without metadata, and for another calendar', function (): void {
    $cache = fillWeeks();
    $from = CarbonImmutable::parse('2026-10-26T00:00:00Z');
    $to = CarbonImmutable::parse('2026-11-09T00:00:00Z');

    expect($cache->read('cal-2', $from, $to))->toBeNull();

    Cache::forget('cirrux:refreshed:cal-1');

    expect($cache->isFresh('cal-1'))->toBeFalse()
        ->and($cache->read('cal-1', $from, $to))->toBeNull();
});

it('reads null once the refresh is older than max_staleness', function (): void {
    config()->set('cirrux.cache.max_staleness', 7200);
    $cache = fillWeeks();
    $from = CarbonImmutable::parse('2026-10-26T00:00:00Z');
    $to = CarbonImmutable::parse('2026-11-09T00:00:00Z');

    $this->travel(7200)->seconds();
    expect($cache->isFresh('cal-1'))->toBeTrue()
        ->and($cache->read('cal-1', $from, $to))->toBeInstanceOf(Collection::class);

    $this->travel(1)->seconds();
    expect($cache->isFresh('cal-1'))->toBeFalse()
        ->and($cache->read('cal-1', $from, $to))->toBeNull();
});

it('expires everything after the ttl', function (): void {
    config()->set('cirrux.cache.ttl', 600);
    fillWeeks();

    $this->travel(601)->seconds();

    expect(weekBucket('2026-W44'))->toBeNull()
        ->and(Cache::get('cirrux:refreshed:cal-1'))->toBeNull();
});

it('names the buckets after the ISO week-year, also around New Year', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-30T12:00:00Z'));

    // 2027-01-01 is a Friday: it still belongs to week 53 of 2026. Week 1 of 2027 starts on 2027-01-04.
    $cache = fillWeeks('2026-12-28T00:00:00Z', '2027-01-11T00:00:00Z', [
        cachedEvent('new-year', '2027-01-01T10:00:00Z', '2027-01-01T11:00:00Z'),
        cachedEvent('next-week', '2027-01-05T10:00:00Z', '2027-01-05T11:00:00Z'),
    ]);

    expect(bucketIds('2026-W53'))->toBe(['new-year'])
        ->and(bucketIds('2027-W01'))->toBe(['next-week'])
        ->and(weekBucket('2027-W02'))->toBeNull()
        ->and(weekBucket('2027-W53'))->toBeNull()
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-12-28T00:00:00Z'), CarbonImmutable::parse('2027-01-11T00:00:00Z'))?->pluck('id')->all())->toBe(['new-year', 'next-week']);
});

it('stores and reads a window that does not start on a Monday', function (): void {
    $cache = fillWeeks('2026-10-28T08:00:00Z', '2026-10-30T08:00:00Z', [cachedEvent('mid', '2026-10-29T10:00:00Z', '2026-10-29T11:00:00Z')]);

    expect($cache->read('cal-1', CarbonImmutable::parse('2026-10-28T08:00:00Z'), CarbonImmutable::parse('2026-10-30T08:00:00Z'))?->pluck('id')->all())->toBe(['mid'])
        ->and(weekBucket('2026-W45'))->toBeNull();
});

it('round-trips events through the cache without loss', function (): void {
    $event = new CalendarEvent(
        'series-1_20261028T100000Z',
        'series-1',
        null,
        CarbonImmutable::parse('2026-10-28T10:00:00.123456Z'),
        CarbonImmutable::parse('2026-10-28T11:30:00.654321Z'),
        false,
        Transparency::Transparent,
        [new Attendee('jan@example.com', 'Jan'), new Attendee('piet@example.com')],
        true,
    );
    $allDay = new CalendarEvent('all-day', null, 'Holiday', CarbonImmutable::parse('2026-10-29T00:00:00Z'), CarbonImmutable::parse('2026-10-30T00:00:00Z'), true, Transparency::Transparent, [], false);

    $cache = fillWeeks(events: [$event, $allDay]);
    $events = $cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'));

    expect($events?->all())->toEqual([$event, $allDay]);
});

it('adds an event to the buckets that exist and never creates a bucket', function (): void {
    $cache = fillWeeks('2026-10-26T00:00:00Z', '2026-11-02T00:00:00Z');

    // Starts in W44 (exists), runs through W45 (does not exist).
    $cache->add('cal-1', cachedEvent('new', '2026-11-01T22:00:00Z', '2026-11-02T10:00:00Z'));

    expect(bucketIds('2026-W44'))->toBe(['new'])
        ->and(weekBucket('2026-W45'))->toBeNull();

    // Entirely in a week that was never fetched: nothing happens.
    $cache->add('cal-1', cachedEvent('far', '2026-12-01T10:00:00Z', '2026-12-01T11:00:00Z'));

    expect(weekBucket('2026-W49'))->toBeNull();
});

it('replaces an event with the same id when it is added again', function (): void {
    $cache = fillWeeks(events: [cachedEvent('a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z', 'Old')]);

    $cache->add('cal-1', cachedEvent('a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z', 'New'));

    expect(array_column((array) weekBucket('2026-W44'), 'title'))->toBe(['New']);
});

it('adds an event of zero length to its week', function (): void {
    $cache = fillWeeks();

    $cache->add('cal-1', cachedEvent('instant', '2026-10-28T10:00:00Z', '2026-10-28T10:00:00Z'));

    expect(bucketIds('2026-W44'))->toBe(['instant']);
});

it('removes an event from every existing bucket of the refresh window', function (): void {
    $cache = fillWeeks(events: [
        cachedEvent('gone', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z'),
        cachedEvent('stays', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z'),
    ]);

    $cache->remove('cal-1', 'gone');

    expect(bucketIds('2026-W44'))->toBe(['stays'])
        ->and(bucketIds('2026-W45'))->toBe([])
        ->and(weekBucket('2026-W46'))->toBeNull();
});

it('removes an event through the weeks of the event itself', function (): void {
    $gone = cachedEvent('gone', '2026-11-01T23:00:00Z', '2026-11-02T01:00:00Z');
    $cache = fillWeeks(events: [$gone]);
    Cache::forget('cirrux:events:cal-1:2026-W45');

    $cache->remove('cal-1', 'gone', $gone);

    expect(bucketIds('2026-W44'))->toBe([])
        ->and(weekBucket('2026-W45'))->toBeNull();
});

it('keeps the cache fail closed when remove finds nothing', function (): void {
    $cache = resolve(EventCache::class);

    $cache->remove('cal-1', 'whatever');

    expect(weekBucket('2026-W44'))->toBeNull();
});

it('forgets the metadata and the buckets from last week through weeks_ahead + 1', function (): void {
    config()->set('cirrux.cache.weeks_ahead', 2);
    $cache = fillWeeks('2026-10-19T00:00:00Z', '2026-11-30T00:00:00Z', [cachedEvent('a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z')]);
    Cache::put('cirrux:events:cal-2:2026-W44', []);

    $cache->forget('cal-1');

    // W43 (last week) up to W47 (this week + weeks_ahead + 1) is gone; W48 is left alone.
    expect(Cache::has('cirrux:refreshed:cal-1'))->toBeFalse()
        ->and(weekBucket('2026-W43'))->toBeNull()
        ->and(weekBucket('2026-W44'))->toBeNull()
        ->and(weekBucket('2026-W47'))->toBeNull()
        ->and(weekBucket('2026-W48'))->toBe([])
        ->and(weekBucket('2026-W44', 'cal-2'))->toBe([])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-02T00:00:00Z')))->toBeNull();
});

it('forgets a calendar through the action', function (): void {
    $cache = fillWeeks();

    resolve(ForgetCalendar::class)->handle('cal-1');

    expect($cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z')))->toBeNull();
});

it('refreshes a window of weeks_ahead weeks from the start of this ISO week', function (): void {
    config()->set('cirrux.cache.weeks_ahead', 3);

    [$from, $to] = resolve(EventCache::class)->refreshWindow();

    expect($from->toIso8601ZuluString())->toBe('2026-10-26T00:00:00Z')
        ->and($to->toIso8601ZuluString())->toBe('2026-11-16T00:00:00Z');
});

it('uses the cache store from the config', function (): void {
    config()->set('cache.stores.cirrux', ['driver' => 'array']);
    config()->set('cirrux.cache.store', 'cirrux');

    resolve(EventCache::class)->replace(
        'cal-1',
        CarbonImmutable::parse('2026-10-26T00:00:00Z'),
        CarbonImmutable::parse('2026-11-02T00:00:00Z'),
        collect(),
        CarbonImmutable::now('UTC'),
    );

    expect(Cache::store('cirrux')->get('cirrux:events:cal-1:2026-W44'))->toBe([])
        ->and(Cache::store('array')->has('cirrux:events:cal-1:2026-W44'))->toBeFalse();
});

it('does not trust a bucket that a later refresh no longer covers', function (): void {
    config()->set('cirrux.cache.ttl', 86400 * 30);

    // First refresh, in week 44: W44 to W47.
    $cache = fillWeeks('2026-10-26T00:00:00Z', '2026-11-23T00:00:00Z', [cachedEvent('old', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z')]);

    // The refresh a week later covers W45 to W48; the bucket of W44 is left behind.
    $this->travelTo(CarbonImmutable::parse('2026-11-02T12:00:00Z'));
    $cache->replace('cal-1', CarbonImmutable::parse('2026-11-02T00:00:00Z'), CarbonImmutable::parse('2026-11-30T00:00:00Z'), collect(), CarbonImmutable::now('UTC'));

    expect(bucketIds('2026-W44'))->toBe(['old'])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z')))->toBeNull()
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z')))->toBeNull()
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-11-02T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'))?->all())->toBe([])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-11-02T00:00:00Z'), CarbonImmutable::parse('2026-11-30T00:00:00Z'))?->all())->toBe([])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-11-02T00:00:00Z'), CarbonImmutable::parse('2026-12-07T00:00:00Z')))->toBeNull();
});

it('does not trust buckets beyond a smaller weeks_ahead', function (): void {
    $cache = fillWeeks('2026-10-26T00:00:00Z', '2026-11-23T00:00:00Z');

    $cache->replace('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'), collect(), CarbonImmutable::now('UTC'));

    expect(weekBucket('2026-W46'))->toBe([])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z'))?->all())->toBe([])
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-11-02T00:00:00Z'), CarbonImmutable::parse('2026-11-16T00:00:00Z')))->toBeNull();
});

it('reads null when the metadata is damaged or incomplete', function (mixed $meta): void {
    $cache = fillWeeks();
    Cache::put('cirrux:refreshed:cal-1', $meta);

    expect($cache->isFresh('cal-1'))->toBeFalse()
        ->and($cache->read('cal-1', CarbonImmutable::parse('2026-10-26T00:00:00Z'), CarbonImmutable::parse('2026-11-09T00:00:00Z')))->toBeNull();
})->with([
    'not an array' => ['2026-10-28T12:00:00+00:00'],
    'no window' => [['refreshed_at' => '2026-10-28T12:00:00+00:00']],
    'no refreshed_at' => [['from' => '2026-10-26T00:00:00+00:00', 'to' => '2026-11-09T00:00:00+00:00']],
    'not a date' => [['refreshed_at' => 'soon', 'from' => '2026-10-26T00:00:00+00:00', 'to' => '2026-11-09T00:00:00+00:00']],
    'wrong type' => [['refreshed_at' => 1, 'from' => '2026-10-26T00:00:00+00:00', 'to' => '2026-11-09T00:00:00+00:00']],
]);

it('only changes buckets inside the covered window', function (): void {
    $cache = fillWeeks('2026-10-26T00:00:00Z', '2026-11-09T00:00:00Z', [cachedEvent('a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z')]);

    // A bucket from an older refresh that the metadata no longer covers.
    Cache::put('cirrux:events:cal-1:2026-W43', [cachedEvent('stale', '2026-10-20T10:00:00Z', '2026-10-20T11:00:00Z')->toArray()]);
    Cache::put('cirrux:events:cal-1:2026-W46', []);

    $cache->add('cal-1', cachedEvent('b', '2026-11-08T22:00:00Z', '2026-11-09T02:00:00Z'));
    $cache->add('cal-1', cachedEvent('outside', '2026-11-10T10:00:00Z', '2026-11-10T11:00:00Z'));
    $cache->remove('cal-1', 'stale');
    $cache->remove('cal-1', 'a', cachedEvent('a', '2026-10-20T10:00:00Z', '2026-11-16T11:00:00Z'));

    // b runs from W45 into W46; W46 lies outside the covered window and is left alone.
    expect(bucketIds('2026-W44'))->toBe([])
        ->and(bucketIds('2026-W45'))->toBe(['b'])
        ->and(bucketIds('2026-W46'))->toBe([])
        ->and(bucketIds('2026-W43'))->toBe(['stale']);
});

it('does not touch any bucket without valid metadata', function (): void {
    $cache = resolve(EventCache::class);
    Cache::put('cirrux:events:cal-1:2026-W44', [cachedEvent('a', '2026-10-28T10:00:00Z', '2026-10-28T11:00:00Z')->toArray()]);

    $cache->add('cal-1', cachedEvent('b', '2026-10-28T12:00:00Z', '2026-10-28T13:00:00Z'));
    $cache->remove('cal-1', 'a');

    expect(bucketIds('2026-W44'))->toBe(['a']);
});

it('does not recreate a covered bucket that went missing', function (): void {
    $cache = fillWeeks();
    Cache::forget('cirrux:events:cal-1:2026-W45');

    $cache->add('cal-1', cachedEvent('b', '2026-11-01T22:00:00Z', '2026-11-02T02:00:00Z'));

    expect(bucketIds('2026-W44'))->toBe(['b'])
        ->and(weekBucket('2026-W45'))->toBeNull();
});
