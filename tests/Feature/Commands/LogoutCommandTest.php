<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\LoggedOut;
use Illuminate\Support\Facades\Event;

it('removes the stored tokens', function (): void {
    Event::fake();
    $store = resolve(TokenStore::class);
    $store->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));

    $this->artisan('cirrux:logout')
        ->expectsOutputToContain('Logged out of Cirrux.')
        ->assertExitCode(0);

    expect($store->get())->toBeNull();
    Event::assertDispatched(LoggedOut::class);
});

it('succeeds when nobody was logged in', function (): void {
    $this->artisan('cirrux:logout')
        ->expectsOutputToContain('Logged out of Cirrux.')
        ->assertExitCode(0);
});
