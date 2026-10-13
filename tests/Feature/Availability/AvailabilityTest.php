<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Availability\Availability;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\Slot;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\CalendarStale;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

// Wednesday 2026-10-28, ISO week 2026-W44. The demo event type is bookable on Monday and Friday,
// 30 minute slots with 10 minute buffers, a day of notice and 21 days ahead; Amsterdam is on CET (UTC+1).
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    config()->set('cirrux.event_types.demo.calendar', 'cal-1');
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

function availabilityPayload(string $id, string $start, string $end): array
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

/**
 * @param  list<array<string, mixed>>  $events
 */
function availabilityFill(array $events = []): void
{
    $cache = resolve(EventCache::class);
    [$from, $to] = $cache->refreshWindow();

    $cache->replace(
        'cal-1',
        $from,
        $to,
        collect($events)->map(static fn (array $event): CalendarEvent => CalendarEvent::fromApi($event)),
        CarbonImmutable::now('UTC'),
    );
}

function availabilityWindow(int $days = 14): Availability
{
    return Cirrux::availability('demo')->between(CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDays($days));
}

/**
 * @return list<string>
 */
function availabilityStarts(Availability $availability): array
{
    return $availability->slots()->map(static fn (Slot $slot): string => $slot->start->toIso8601ZuluString())->all();
}

it('hands out the slots from the cache without a single API call, however often it is asked', function (): void {
    Saloon::fake([]);
    availabilityFill();

    $first = availabilityStarts(availabilityWindow());
    $second = availabilityStarts(availabilityWindow());

    // Friday 10-30 09:00 CET is the first valid start: Thursday has no hours and the notice runs to Thursday noon.
    expect($first)->toBe($second)
        ->and($first)->toHaveCount(56)
        ->and($first[0])->toBe('2026-10-30T08:00:00Z')
        ->and($first[13])->toBe('2026-10-30T15:30:00Z')
        ->and($first[14])->toBe('2026-11-02T08:00:00Z');

    Saloon::assertSentCount(0);
});

it('leaves out the slots that an opaque event or its buffers touch', function (): void {
    availabilityFill([availabilityPayload('busy', '2026-10-30T08:30:00Z', '2026-10-30T09:00:00Z')]);

    // 08:00 (the buffer after runs into the event), 08:30 and 09:00 (the buffer before) are gone.
    expect(availabilityStarts(availabilityWindow())[0])->toBe('2026-10-30T09:30:00Z');
});

it('clips the window to max_days_ahead', function (): void {
    availabilityFill();

    $starts = availabilityStarts(Cirrux::availability('demo')->between(CarbonImmutable::now('UTC')->subDays(5), CarbonImmutable::now('UTC')->addDays(100)));

    // The horizon is Wednesday 11-18 12:00Z: Monday 11-16 is the last bookable day.
    expect($starts[0])->toBe('2026-10-30T08:00:00Z')
        ->and(end($starts))->toBe('2026-11-16T15:30:00Z');
});

it('returns nothing for a window in the past without reading the cache', function (): void {
    Event::fake([CalendarStale::class]);

    $slots = Cirrux::availability('demo')->between(CarbonImmutable::now('UTC')->subDays(10), CarbonImmutable::now('UTC')->subDays(5))->slots();
    $beyond = Cirrux::availability('demo')->between(CarbonImmutable::now('UTC')->addDays(30), CarbonImmutable::now('UTC')->addDays(40))->slots();

    // The cache was never filled: reading it would have failed closed and announced it.
    expect($slots)->toBeEmpty()
        ->and($beyond)->toBeEmpty();
    Event::assertNotDispatched(CalendarStale::class);
});

it('needs a window', function (): void {
    expect(fn (): mixed => Cirrux::availability('demo')->slots())->toThrow(LogicException::class, 'Call between() before slots().');
});

it('fails closed with an empty result and one CalendarStale per max_staleness', function (): void {
    Event::fake([CalendarStale::class]);

    expect(availabilityWindow()->slots())->toBeEmpty()
        ->and(availabilityWindow()->slots())->toBeEmpty();

    Event::assertDispatchedTimes(CalendarStale::class, 1);
    Event::assertDispatched(CalendarStale::class, fn (CalendarStale $event): bool => $event->calendarUuid === 'cal-1' && ! $event->refreshedAt instanceof CarbonImmutable);

    // Still inside max_staleness (7200 seconds): no second event. After it: another one.
    $this->travelTo(CarbonImmutable::now('UTC')->addSeconds(7199));
    availabilityWindow()->slots();
    Event::assertDispatchedTimes(CalendarStale::class, 1);

    $this->travelTo(CarbonImmutable::now('UTC')->addSeconds(2));
    availabilityWindow()->slots();
    Event::assertDispatchedTimes(CalendarStale::class, 2);
});

it('reports when the stale cache was last refreshed', function (): void {
    Event::fake([CalendarStale::class]);
    availabilityFill();

    $this->travelTo(CarbonImmutable::now('UTC')->addHours(3));

    expect(availabilityWindow()->slots())->toBeEmpty();
    Event::assertDispatched(CalendarStale::class, fn (CalendarStale $event): bool => $event->refreshedAt?->toIso8601ZuluString() === '2026-10-28T12:00:00Z');
});

it('keeps the stale flag in the cirrux cache store', function (): void {
    config()->set('cache.stores.cirrux', ['driver' => 'array']);
    config()->set('cirrux.cache.store', 'cirrux');
    Event::fake([CalendarStale::class]);

    availabilityWindow()->slots();

    expect(Cache::store('cirrux')->has('cirrux:stale-reported:cal-1'))->toBeTrue()
        ->and(Cache::store('array')->has('cirrux:stale-reported:cal-1'))->toBeFalse();
});

it('does not catch other exceptions while reading slots', function (): void {
    Cache::put('cirrux:refreshed:cal-1', ['refreshed_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'from' => '2026-10-26T00:00:00+00:00', 'to' => '2026-11-30T00:00:00+00:00']);
    Cache::put('cirrux:events:cal-1:2026-W44', [['id' => 'broken']]);

    expect(fn (): Collection => availabilityWindow(3)->slots())->toThrow(Exception::class);
});

it('presents the slots in another time zone without moving them', function (): void {
    availabilityFill();

    $utc = availabilityWindow()->slots();
    $newYork = availabilityWindow()->inTimezone('America/New_York')->slots();

    expect($newYork)->toHaveCount($utc->count())
        ->and($newYork->first()?->start->getTimestamp())->toBe($utc->first()?->start->getTimestamp())
        ->and($newYork->first()?->end->getTimestamp())->toBe($utc->first()?->end->getTimestamp())
        ->and($newYork->first()?->start->getTimezone()->getName())->toBe('America/New_York')
        ->and($newYork->first()?->start->format('Y-m-d H:i'))->toBe('2026-10-30 04:00');
});

it('refuses an unknown time zone', function (): void {
    expect(fn (): Availability => Cirrux::availability('demo')->inTimezone('Mars/Olympus'))
        ->toThrow(InvalidArgumentException::class, '"Mars/Olympus" is not a valid time zone identifier.');
});

it('keeps serving slots around the start of a week: the refresh window moves with the clock', function (): void {
    Event::fake([CalendarStale::class]);
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);
    $tokens = static fn () => resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));

    $refreshAndRead = function (string $refreshedAt, string $readAt) use ($tokens): void {
        $this->travelTo(CarbonImmutable::parse($refreshedAt));
        $tokens();
        resolve(RefreshCalendar::class)->handle('cal-1');
        $this->travelTo(CarbonImmutable::parse($readAt));

        expect(availabilityStarts(availabilityWindow()))->not->toBeEmpty();
    };

    // Refresh on Sunday 23:55, read in the first minutes of Monday, before the next refresh.
    $refreshAndRead('2026-11-01T23:55:00Z', '2026-11-02T00:03:00Z');

    // The refresh of Monday 00:05 and a read right after it, then Monday 10:00 and Wednesday 12:00.
    $refreshAndRead('2026-11-02T00:05:00Z', '2026-11-02T00:06:00Z');
    $refreshAndRead('2026-11-02T09:55:00Z', '2026-11-02T10:00:00Z');
    $refreshAndRead('2026-11-04T11:55:00Z', '2026-11-04T12:00:00Z');

    Event::assertNotDispatched(CalendarStale::class);
});

it('covers the longest read of a fresh refresh, up to max_staleness later', function (): void {
    Event::fake([CalendarStale::class]);
    config()->set('cirrux.cache.max_staleness', 86400);
    config()->set('cirrux.event_types.demo', [
        ...config('cirrux.event_types.demo'),
        'buffer_before' => 1440,
        'duration' => 30,
        'buffer_after' => 1410,
        'max_days_ahead' => 21,
    ]);
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    resolve(RefreshCalendar::class)->handle('cal-1');
    $this->travelTo(CarbonImmutable::now('UTC')->addSeconds(86399));

    Cirrux::availability('demo')->between(CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDays(100))->slots();

    Event::assertNotDispatched(CalendarStale::class);
});

it('still covers the whole horizon late on a Sunday', function (): void {
    Event::fake([CalendarStale::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01T23:59:00Z'));
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    resolve(RefreshCalendar::class)->handle('cal-1');

    $starts = availabilityStarts(Cirrux::availability('demo')->between(CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDays(100)));

    // The horizon ends on Sunday 11-22 23:59Z, so Friday 11-20 is the last bookable day.
    expect($starts)->not->toBeEmpty()
        ->and(end($starts))->toBe('2026-11-20T15:30:00Z');
    Event::assertNotDispatched(CalendarStale::class);
});

describe('isFree', function (): void {
    it('says no to a start that is not a valid slot, without asking Cirrux', function (string $start): void {
        Saloon::fake([]);

        expect(Cirrux::availability('demo')->isFree(CarbonImmutable::parse($start)))->toBeFalse();

        Saloon::assertSentCount(0);
    })->with([
        'on a day without hours' => '2026-10-31T08:00:00Z',
        'off the grid' => '2026-10-30T08:15:00Z',
        'too soon' => '2026-10-29T12:00:00Z',
        'in the past' => '2026-10-23T08:00:00Z',
    ]);

    it('asks Cirrux for fresh events and says yes to a free slot', function (): void {
        Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => [
            availabilityPayload('elsewhere', '2026-10-30T12:00:00Z', '2026-10-30T13:00:00Z'),
        ]])]);

        expect(Cirrux::availability('demo')->isFree(CarbonImmutable::parse('2026-10-30T08:00:00Z')->setTimezone('Asia/Tokyo')))->toBeTrue();

        Saloon::assertSentCount(1);
    });

    it('says no when the buffers of an event touch the slot', function (): void {
        Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => [
            availabilityPayload('busy', '2026-10-30T08:30:00Z', '2026-10-30T09:00:00Z'),
        ]])]);

        expect(Cirrux::availability('demo')->isFree(CarbonImmutable::parse('2026-10-30T08:00:00Z')))->toBeFalse();

        Saloon::assertSentCount(1);
    });

    it('does not use the cache and lets request failures through', function (): void {
        availabilityFill([availabilityPayload('busy', '2026-10-30T08:00:00Z', '2026-10-30T08:30:00Z')]);
        Saloon::fake([ListEvents::class => MockResponse::make(['error' => 'boom'], 500)]);

        expect(fn (): bool => Cirrux::availability('demo')->isFree(CarbonImmutable::parse('2026-10-30T08:00:00Z')))
            ->toThrow(CirruxRequestException::class);
    });
});

describe('Cirrux::availability', function (): void {
    it('builds the query of a configured event type', function (): void {
        expect(Cirrux::availability('demo'))->toBeInstanceOf(Availability::class);
    });

    it('refuses an unknown event type', function (): void {
        expect(fn (): Availability => Cirrux::availability('nope'))
            ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.nope" must be an array');
    });

    it('refuses an event type without a calendar', function (mixed $calendar): void {
        config()->set('cirrux.event_types.demo.calendar', $calendar);

        expect(fn (): Availability => Cirrux::availability('demo'))
            ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.demo.calendar" must be the uuid of a calendar');
    })->with(['null' => null, 'empty' => '']);

    it('refuses an invalid event type', function (): void {
        config()->set('cirrux.event_types.demo.duration', 0);

        expect(fn (): Availability => Cirrux::availability('demo'))
            ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.demo.duration"');
    });
});
