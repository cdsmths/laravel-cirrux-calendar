<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Calendars\ListCalendars;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function loginForCommand(): void
{
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
}

it('lists the calendars with name, uuid and color', function (): void {
    loginForCommand();
    Saloon::fake([
        ListCalendars::class => MockResponse::make([
            'data' => [
                ['calendar_uuid' => 'cal-1', 'name' => 'Demo', 'color' => '#ff8800'],
                ['calendar_uuid' => 'cal-2', 'name' => 'Private', 'color' => null],
            ],
        ]),
    ]);

    $this->artisan('cirrux:calendars')
        ->expectsTable(['Name', 'UUID', 'Color'], [
            ['Demo', 'cal-1', '#ff8800'],
            ['Private', 'cal-2', '-'],
        ])
        ->assertExitCode(0);
});

it('always asks Cirrux and refreshes the cached list', function (): void {
    loginForCommand();
    Cache::put('cirrux:calendars', [['uuid' => 'old', 'name' => 'Old', 'color' => null]], 3600);
    Saloon::fake([ListCalendars::class => MockResponse::make(['data' => [['calendar_uuid' => 'cal-1', 'name' => 'Demo']]])]);

    $this->artisan('cirrux:calendars')->assertExitCode(0);

    expect(Cirrux::calendars()->pluck('uuid')->all())->toBe(['cal-1']);
    Saloon::assertSentCount(1);
});

it('says so when there are no calendars', function (): void {
    loginForCommand();
    Saloon::fake([ListCalendars::class => MockResponse::make(['data' => []])]);

    $this->artisan('cirrux:calendars')
        ->expectsOutputToContain('This Cirrux account has no calendars.')
        ->assertExitCode(0);
});

it('points to cirrux:login when not logged in', function (): void {
    Saloon::fake([ListCalendars::class => MockResponse::make(['data' => []])]);

    $this->artisan('cirrux:calendars')
        ->expectsOutputToContain('Not logged in to Cirrux.')
        ->expectsOutputToContain('php artisan cirrux:login')
        ->assertExitCode(1);

    Saloon::assertNothingSent();
});

it('shows the error when Cirrux fails', function (): void {
    loginForCommand();
    Saloon::fake([ListCalendars::class => MockResponse::make(['error' => 'boom'], 500)]);

    $this->artisan('cirrux:calendars')
        ->expectsOutputToContain('500')
        ->assertExitCode(1);
});
