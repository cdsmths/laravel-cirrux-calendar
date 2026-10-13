<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\ConfiguredCalendars;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

function managementCalendars(mixed ...$calendars): void
{
    $types = [];

    foreach ($calendars as $index => $calendar) {
        $types['type-'.$index] = [...config('cirrux.event_types.demo'), 'calendar' => $calendar];
    }

    config()->set('cirrux.event_types', $types);
}

function managementSeed(string $calendar): void
{
    Cache::put('cirrux:refreshed:'.$calendar, ['refreshed_at' => 'x', 'from' => 'x', 'to' => 'x'], 3600);
}

it('lists the distinct, filled-in calendars of the event types', function (): void {
    managementCalendars('cal-1', null, '', 'cal-2', 'cal-1', 42);
    config()->set('cirrux.event_types.broken', 'not-an-array');

    expect(resolve(ConfiguredCalendars::class)->uuids())->toBe(['cal-1', 'cal-2']);
});

it('refreshes the given calendar', function (): void {
    managementCalendars('cal-1');
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    Cirrux::refresh('cal-other');

    Saloon::assertSentCount(1);
    expect(Cache::has('cirrux:refreshed:cal-other'))->toBeTrue()
        ->and(Cache::has('cirrux:refreshed:cal-1'))->toBeFalse();
});

it('refreshes every configured calendar without an argument', function (): void {
    managementCalendars('cal-1', 'cal-2');
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    Cirrux::refresh();

    Saloon::assertSentCount(2);
    expect(Cache::has('cirrux:refreshed:cal-1'))->toBeTrue()
        ->and(Cache::has('cirrux:refreshed:cal-2'))->toBeTrue();
});

it('does nothing without configured calendars', function (): void {
    managementCalendars();
    Saloon::fake([]);

    Cirrux::refresh();
    Cirrux::forget();

    Saloon::assertNothingSent();
});

it('tries every calendar and then throws the first failure', function (): void {
    managementCalendars('cal-1', 'cal-2', 'cal-3');
    Saloon::fake([
        MockResponse::make(['error' => 'first'], 500),
        MockResponse::make(['has_more' => false, 'data' => []]),
        MockResponse::make(['error' => 'third'], 503),
    ]);

    try {
        Cirrux::refresh();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe(500);
    }

    Saloon::assertSentCount(3);
    expect(Cache::has('cirrux:refreshed:cal-2'))->toBeTrue();
});

it('forgets the given calendar', function (): void {
    managementCalendars('cal-1');
    managementSeed('cal-1');
    managementSeed('cal-other');

    Cirrux::forget('cal-other');

    expect(Cache::has('cirrux:refreshed:cal-other'))->toBeFalse()
        ->and(Cache::has('cirrux:refreshed:cal-1'))->toBeTrue();
});

it('forgets every configured calendar without an argument', function (): void {
    managementCalendars('cal-1', 'cal-2');
    managementSeed('cal-1');
    managementSeed('cal-2');
    managementSeed('cal-other');

    Cirrux::forget();

    expect(Cache::has('cirrux:refreshed:cal-1'))->toBeFalse()
        ->and(Cache::has('cirrux:refreshed:cal-2'))->toBeFalse()
        ->and(Cache::has('cirrux:refreshed:cal-other'))->toBeTrue();
});
