<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\CirruxCalendarServiceProvider;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;

function bootCirruxProvider(): void
{
    (new CirruxCalendarServiceProvider(app()))->packageBooted();
}

function expectInvalidCirruxConfig(string $message): void
{
    expect(bootCirruxProvider(...))->toThrow(InvalidConfigurationException::class, $message);
}

it('accepts the default config', function (): void {
    bootCirruxProvider();

    expect(InvalidConfigurationException::forKey('cirrux.x', 'is wrong'))
        ->toBeInstanceOf(CirruxException::class)
        ->getMessage()->toBe('Invalid Cirrux configuration: "cirrux.x" is wrong.');
});

it('accepts an event type without a calendar', function (): void {
    config()->set('cirrux.event_types.demo.calendar');

    bootCirruxProvider();
})->throwsNoExceptions();

it('rejects a number that is out of range or not an integer', function (string $key, mixed $value, string $message): void {
    config()->set($key, $value);

    expectInvalidCirruxConfig($message);
})->with([
    'weeks_ahead zero' => ['cirrux.cache.weeks_ahead', 0, '"cirrux.cache.weeks_ahead" must be an integer of at least 1'],
    'weeks_ahead string' => ['cirrux.cache.weeks_ahead', '4', '"cirrux.cache.weeks_ahead"'],
    'max_staleness zero' => ['cirrux.cache.max_staleness', 0, '"cirrux.cache.max_staleness" must be an integer of at least 1'],
    'ttl below max_staleness' => ['cirrux.cache.ttl', 7199, '"cirrux.cache.ttl" must be an integer of at least cirrux.cache.max_staleness'],
    'calendars_ttl zero' => ['cirrux.cache.calendars_ttl', 0, '"cirrux.cache.calendars_ttl"'],
    'lock timeout negative' => ['cirrux.lock.timeout', -1, '"cirrux.lock.timeout" must be an integer of at least 0'],
    'per_minute zero' => ['cirrux.rate_limit.per_minute', 0, '"cirrux.rate_limit.per_minute"'],
]);

it('accepts a lock timeout of zero and a ttl equal to max_staleness', function (): void {
    config()->set('cirrux.lock.timeout', 0);
    config()->set('cirrux.cache.ttl', 7200);

    bootCirruxProvider();
})->throwsNoExceptions();

it('rejects an event type that is not an array', function (): void {
    config()->set('cirrux.event_types', 'demo');
    expectInvalidCirruxConfig('"cirrux.event_types" must be an array');

    config()->set('cirrux.event_types', ['demo' => 'x']);
    expectInvalidCirruxConfig('"cirrux.event_types.demo" must be an array');
});

it('rejects an unknown time zone', function (mixed $timezone): void {
    config()->set('cirrux.event_types.demo.timezone', $timezone);

    expectInvalidCirruxConfig('"cirrux.event_types.demo.timezone"');
})->with(['unknown' => 'Mars/Olympus', 'null' => null, 'int' => 1]);

it('rejects max_days_ahead beyond the cached weeks', function (mixed $days): void {
    config()->set('cirrux.cache.weeks_ahead', 2);
    config()->set('cirrux.event_types.demo.max_days_ahead', $days);

    expectInvalidCirruxConfig('"cirrux.event_types.demo.max_days_ahead"');
})->with(['too far' => 15, 'string' => '7', 'missing' => null]);

it('accepts max_days_ahead of exactly weeks_ahead times seven', function (): void {
    config()->set('cirrux.cache.weeks_ahead', 2);
    config()->set('cirrux.event_types.demo.max_days_ahead', 14);

    bootCirruxProvider();
})->throwsNoExceptions();

it('rejects invalid working hours', function (mixed $hours, string $message): void {
    config()->set('cirrux.event_types.demo.hours', $hours);

    expectInvalidCirruxConfig($message);
})->with([
    'not an array' => ['9-17', '"cirrux.event_types.demo.hours" must be an array'],
    'unknown day' => [['monday' => [['09:00', '17:00']]], 'has the day "monday"'],
    'numeric day' => [[['09:00', '17:00']], 'has the day "0"'],
    'day is not a list' => [['mon' => ['from' => ['09:00', '17:00']]], '"cirrux.event_types.demo.hours.mon" must be a list'],
    'day is a string' => [['mon' => '09:00-17:00'], '"cirrux.event_types.demo.hours.mon" must be a list'],
    'period is a string' => [['mon' => ['09:00-17:00']], 'periods that end after they start'],
    'period has three times' => [['mon' => [['09:00', '12:00', '17:00']]], 'periods that end after they start'],
    'period is keyed' => [['mon' => [['start' => '09:00', 'end' => '17:00']]], 'periods that end after they start'],
    'time is not a string' => [['mon' => [[9, 17]]], 'periods that end after they start'],
    'time without padding' => [['mon' => [['9:00', '17:00']]], 'periods that end after they start'],
    'hour out of range' => [['mon' => [['09:00', '24:00']]], 'periods that end after they start'],
    'minute out of range' => [['mon' => [['09:60', '17:00']]], 'periods that end after they start'],
    'start after end' => [['mon' => [['17:00', '09:00']]], 'periods that end after they start'],
    'start equals end' => [['mon' => [['09:00', '09:00']]], 'periods that end after they start'],
]);

it('accepts every weekday, a day without periods and a missing hours day', function (): void {
    config()->set('cirrux.event_types.demo.hours', [
        'mon' => [['00:00', '23:59']],
        'tue' => [['08:30', '12:00'], ['13:00', '17:45']],
        'wed' => [],
        'thu' => [['09:00', '10:00']],
        'fri' => [['09:00', '10:00']],
        'sat' => [['09:00', '10:00']],
        'sun' => [['09:00', '10:00']],
    ]);

    bootCirruxProvider();
})->throwsNoExceptions();

it('checks every event type, not only the first', function (): void {
    config()->set('cirrux.event_types.second', [...config('cirrux.event_types.demo'), 'timezone' => 'Nowhere/Land']);

    expectInvalidCirruxConfig('"cirrux.event_types.second.timezone"');
});

describe('cache store outside testing', function (): void {
    beforeEach(function (): void {
        $this->app['env'] = 'production';
    });

    // Testbench rolls its migrations back afterwards, which asks for confirmation in production.
    afterEach(function (): void {
        $this->app['env'] = 'testing';
    });

    it('rejects the array and null drivers, set explicitly or as the default', function (string $driver): void {
        config()->set('cache.stores.local-only', ['driver' => $driver]);
        config()->set('cirrux.cache.store', 'local-only');
        expectInvalidCirruxConfig('"cirrux.cache.store" uses the "'.$driver.'" cache driver');

        config(['cirrux.cache.store' => null, 'cache.default' => 'local-only']);
        expectInvalidCirruxConfig('"cirrux.cache.store" uses the "'.$driver.'" cache driver');
    })->with(['array', 'null']);

    it('accepts a store that locks across processes', function (): void {
        config()->set('cache.stores.shared', ['driver' => 'redis']);
        config()->set('cirrux.cache.store', 'shared');

        bootCirruxProvider();
    })->throwsNoExceptions();

    it('leaves a store it does not know to the cache layer', function (): void {
        config()->set('cirrux.cache.store', 'unknown');

        bootCirruxProvider();
    })->throwsNoExceptions();
});

it('does not check the cache store while testing', function (): void {
    config()->set('cirrux.cache.store', 'array');

    bootCirruxProvider();
})->throwsNoExceptions();
