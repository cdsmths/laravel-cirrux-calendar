<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Calendar;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Calendars\ListCalendars;
use Illuminate\Support\Collection;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
});

it('maps the calendar list to calendar dtos', function (): void {
    Saloon::fake([
        ListCalendars::class => MockResponse::make([
            'object' => 'list',
            'url' => '/public_api/v1/calendars',
            'has_more' => false,
            'data' => [
                [
                    'object' => 'calendar',
                    'uuid' => 'link-1',
                    'calendar_uuid' => 'cal-1',
                    'mailbox_uuid' => 'mailbox-1',
                    'mailbox_address' => 'thomas@example.com',
                    'name' => 'Demo',
                    'description' => null,
                    'color' => '#ff8800',
                    'role' => 'owner',
                    'can_write' => true,
                    'source' => 'native',
                    'position' => 0,
                    'is_default' => true,
                ],
                ['uuid' => 'link-2', 'calendar_uuid' => 'cal-2', 'name' => 'Holidays', 'color' => null, 'can_write' => false, 'source' => 'subscribed'],
                ['calendar_uuid' => 'cal-3', 'name' => 'Private', 'color' => null],
            ],
        ]),
    ]);

    $response = resolve(CirruxConnector::class)->send(new ListCalendars);
    $calendars = $response->dto();

    expect($response->getPendingRequest()->getUrl())->toBe('https://api.cirrux.co/public_api/v1/calendars')
        ->and($calendars)->toBeInstanceOf(Collection::class)
        ->and($calendars->all())->toEqual([
            new Calendar('cal-1', 'Demo', '#ff8800', 'thomas@example.com', true),
            new Calendar('cal-2', 'Holidays', null, null, false),
            new Calendar('cal-3', 'Private', null),
        ])
        ->and($calendars->keys()->all())->toBe([0, 1, 2]);
});

it('maps an empty list to an empty collection', function (): void {
    Saloon::fake([ListCalendars::class => MockResponse::make(['data' => []])]);

    $calendars = resolve(CirruxConnector::class)->send(new ListCalendars)->dto();

    expect($calendars)->toBeInstanceOf(Collection::class)
        ->and($calendars->isEmpty())->toBeTrue();
});
