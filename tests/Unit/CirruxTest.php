<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Cirrux;
use Codesmiths\CirruxCalendar\Facades\Cirrux as CirruxFacade;

it('loads the package config', function (): void {
    expect(config('cirrux.client_id'))->toBe('cirrux-cli')
        ->and(config('cirrux.base_url'))->toBe('https://api.cirrux.co/')
        ->and(config('cirrux.event_types.demo.slot_interval'))->toBe(30);
});

it('resolves the facade to the shared entry class', function (): void {
    expect(CirruxFacade::getFacadeRoot())
        ->toBeInstanceOf(Cirrux::class)
        ->toBe(resolve(Cirrux::class));
});
