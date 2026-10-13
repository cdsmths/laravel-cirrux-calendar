<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Calendars\ListCalendars;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function calendarsResponse(): MockResponse
{
    return MockResponse::make([
        'data' => [
            ['calendar_uuid' => 'cal-1', 'name' => 'Demo', 'color' => '#ff8800'],
            ['calendar_uuid' => 'cal-2', 'name' => 'Private', 'color' => null],
        ],
    ]);
}

beforeEach(function (): void {
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addDays(7)));
});

it('lists the calendars of the logged in user', function (): void {
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    $calendars = Cirrux::calendars();

    expect($calendars)->toBeInstanceOf(Collection::class)
        ->and($calendars->all())->toEqual([
            new Calendar('cal-1', 'Demo', '#ff8800'),
            new Calendar('cal-2', 'Private', null),
        ]);
    Saloon::assertSentCount(1);
});

it('serves the second call from the cache without a request', function (): void {
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    $first = Cirrux::calendars();
    $second = Cirrux::calendars();

    expect($second->all())->toEqual($first->all())
        ->and(Cache::has('cirrux:calendars'))->toBeTrue();
    Saloon::assertSentCount(1);
});

it('asks Cirrux again once the cache expired', function (): void {
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    Cirrux::calendars();
    $this->travel(config()->integer('cirrux.cache.calendars_ttl') + 1)->seconds();
    Cirrux::calendars();

    Saloon::assertSentCount(2);
});

it('caches for the configured number of seconds', function (): void {
    config()->set('cirrux.cache.calendars_ttl', 60);
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    Cirrux::calendars();
    $this->travel(59)->seconds();
    Cirrux::calendars();
    $this->travel(2)->seconds();
    Cirrux::calendars();

    Saloon::assertSentCount(2);
});

it('survives a cache that serializes values and refuses to unserialize objects', function (): void {
    config()->set('cache.stores.serialized', ['driver' => 'array', 'serialize' => true]);
    config()->set('cache.serializable_classes', false);
    config()->set('cirrux.cache.store', 'serialized');
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    Cirrux::calendars();
    $calendars = Cirrux::calendars();

    expect($calendars->all())->toEqual([
        new Calendar('cal-1', 'Demo', '#ff8800'),
        new Calendar('cal-2', 'Private', null),
    ]);
    Saloon::assertSentCount(1);
});

it('uses the cache store from the config', function (): void {
    config()->set('cache.stores.cirrux', ['driver' => 'array']);
    config()->set('cirrux.cache.store', 'cirrux');
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    Cirrux::calendars();

    expect(Cache::store('cirrux')->has('cirrux:calendars'))->toBeTrue()
        ->and(Cache::store('array')->has('cirrux:calendars'))->toBeFalse();
});

it('falls back to the default cache store without a configured store', function (): void {
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    Cirrux::calendars();

    expect(Cache::store()->has('cirrux:calendars'))->toBeTrue();
});

it('does not cache a failed lookup', function (): void {
    resolve(TokenStore::class)->forget();
    Saloon::fake([ListCalendars::class => calendarsResponse()]);

    try {
        Cirrux::calendars();
    } catch (CirruxAuthException) {
        // expected: not logged in
    }

    expect(Cache::has('cirrux:calendars'))->toBeFalse();
    Saloon::assertNothingSent();
});

it('round-trips a calendar through an array', function (): void {
    $calendar = new Calendar('cal-1', 'Demo', '#ff8800');

    expect(Calendar::fromArray($calendar->toArray()))->toEqual($calendar)
        ->and(Calendar::fromArray(['uuid' => 'cal-2', 'name' => 'Private']))->toEqual(new Calendar('cal-2', 'Private', null));
});

it('refreshes the list from Cirrux and replaces the cached one', function (): void {
    Saloon::fake([
        MockResponse::make(['data' => [['calendar_uuid' => 'old', 'name' => 'Old', 'color' => null]]]),
        calendarsResponse(),
    ]);
    $list = resolve(CalendarList::class);

    $list->get();

    $fresh = $list->refresh();

    expect($fresh->pluck('uuid')->all())->toBe(['cal-1', 'cal-2'])
        ->and($list->get()->pluck('uuid')->all())->toBe(['cal-1', 'cal-2']);
    Saloon::assertSentCount(2);
});
