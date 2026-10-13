<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

function refreshCommandCalendars(string ...$calendars): void
{
    $types = [];

    foreach ($calendars as $index => $calendar) {
        $types['type-'.$index] = [...config('cirrux.event_types.demo'), 'calendar' => $calendar];
    }

    config()->set('cirrux.event_types', $types);
}

it('refreshes every configured calendar', function (): void {
    refreshCommandCalendars('cal-1', 'cal-2', 'cal-1');
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    $this->artisan('cirrux:refresh')
        ->expectsOutputToContain('cal-1 refreshed.')
        ->expectsOutputToContain('cal-2 refreshed.')
        ->assertExitCode(0);

    Saloon::assertSentCount(2);
});

it('refreshes only the calendar that is given, configured or not', function (): void {
    refreshCommandCalendars('cal-1');
    Saloon::fake([ListEvents::class => MockResponse::make(['has_more' => false, 'data' => []])]);

    $this->artisan('cirrux:refresh', ['calendar' => 'cal-other'])
        ->expectsOutputToContain('cal-other refreshed.')
        ->assertExitCode(0);

    Saloon::assertSentCount(1);
});

it('warns and succeeds when no calendar is configured', function (): void {
    refreshCommandCalendars();
    Saloon::fake([]);

    $this->artisan('cirrux:refresh')
        ->expectsOutputToContain('No calendars are configured.')
        ->assertExitCode(0);

    Saloon::assertNothingSent();
});

it('tries every calendar and fails when one of them fails', function (): void {
    refreshCommandCalendars('cal-1', 'cal-2');
    Saloon::fake([
        MockResponse::make(['error' => 'boom'], 500),
        MockResponse::make(['has_more' => false, 'data' => []]),
    ]);

    $this->artisan('cirrux:refresh')
        ->expectsOutputToContain('cal-1 failed:')
        ->expectsOutputToContain('cal-2 refreshed.')
        ->assertExitCode(1);

    Saloon::assertSentCount(2);
});
