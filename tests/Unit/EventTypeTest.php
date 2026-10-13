<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;

/**
 * @return array<string, mixed>
 */
function eventTypeConfig(): array
{
    return [
        'calendar' => 'calendar-1',
        'title' => 'Demo',
        'duration' => 30,
        'buffer_before' => 10,
        'buffer_after' => 10,
        'slot_interval' => 30,
        'min_notice' => 1440,
        'max_days_ahead' => 21,
        'timezone' => 'Europe/Amsterdam',
        'hours' => ['mon' => [['09:00', '12:00'], ['13:00', '17:00']]],
    ];
}

it('reads the default demo event type of the package config', function (): void {
    $config = config('cirrux.event_types.demo');

    expect($config)->toBeArray();

    /** @var array<array-key, mixed> $config */
    $type = EventType::fromConfig('demo', $config);

    expect($type->name)->toBe('demo')
        ->and($type->calendar)->toBeNull()
        ->and($type->title)->toBe('Demo')
        ->and($type->duration)->toBe(30)
        ->and($type->bufferBefore)->toBe(10)
        ->and($type->bufferAfter)->toBe(10)
        ->and($type->slotInterval)->toBe(30)
        ->and($type->minNotice)->toBe(1440)
        ->and($type->maxDaysAhead)->toBe(21)
        ->and($type->timezone)->toBe('Europe/Amsterdam')
        ->and($type->hours)->toBe([
            'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
            'fri' => [['09:00', '12:00'], ['13:00', '17:00']],
        ]);
});

it('accepts the edges of every range', function (): void {
    $type = EventType::fromConfig('edge', array_merge(eventTypeConfig(), [
        'calendar' => null,
        'duration' => 1,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval' => 1,
        'min_notice' => 0,
        'max_days_ahead' => 1,
        'hours' => [],
    ]));

    expect($type->calendar)->toBeNull()
        ->and($type->hours)->toBe([])
        ->and(EventType::fromConfig('edge', array_merge(eventTypeConfig(), ['hours' => ['sun' => [['00:00', '23:59']], 'tue' => []]]))->hours)
        ->toBe(['sun' => [['00:00', '23:59']], 'tue' => []]);
});

it('does not check max_days_ahead against weeks_ahead', function (): void {
    expect(EventType::fromConfig('far', array_merge(eventTypeConfig(), ['max_days_ahead' => 10_000]))->maxDaysAhead)->toBe(10_000);
});

it('names the key of every invalid value', function (string $suffix, string $field, mixed $value): void {
    $config = array_merge(eventTypeConfig(), [$field => $value]);

    expect(fn (): EventType => EventType::fromConfig('demo', $config))
        ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.demo.'.$suffix.'"');
})->with([
    'calendar is no string' => ['calendar', 'calendar', 5],
    'title is empty' => ['title', 'title', ''],
    'title is no string' => ['title', 'title', 12],
    'title is null' => ['title', 'title', null],
    'duration is zero' => ['duration', 'duration', 0],
    'duration is a string' => ['duration', 'duration', '30'],
    'duration is missing' => ['duration', 'duration', null],
    'buffer_before is negative' => ['buffer_before', 'buffer_before', -1],
    'buffer_after is negative' => ['buffer_after', 'buffer_after', -1],
    'buffer_after is a float' => ['buffer_after', 'buffer_after', 10.5],
    'slot_interval is zero' => ['slot_interval', 'slot_interval', 0],
    'min_notice is negative' => ['min_notice', 'min_notice', -1],
    'max_days_ahead is zero' => ['max_days_ahead', 'max_days_ahead', 0],
    'timezone is unknown' => ['timezone', 'timezone', 'Mars/Phobos'],
    'timezone is no string' => ['timezone', 'timezone', null],
    'hours is no array' => ['hours', 'hours', 'always'],
    'hours has an unknown day' => ['hours', 'hours', ['monday' => [['09:00', '10:00']]]],
    'hours has a numeric day' => ['hours', 'hours', [[['09:00', '10:00']]]],
    'a day is no list' => ['hours.mon', 'hours', ['mon' => '09:00-10:00']],
    'a day has named periods' => ['hours.mon', 'hours', ['mon' => ['morning' => ['09:00', '10:00']]]],
    'a period is no array' => ['hours.mon', 'hours', ['mon' => ['09:00']]],
    'a period has one time' => ['hours.mon', 'hours', ['mon' => [['09:00']]]],
    'a period has three times' => ['hours.mon', 'hours', ['mon' => [['09:00', '10:00', '11:00']]]],
    'a period has named times' => ['hours.mon', 'hours', ['mon' => [['from' => '09:00', 'to' => '10:00']]]],
    'a time is not padded' => ['hours.mon', 'hours', ['mon' => [['9:00', '10:00']]]],
    'a time is not a time' => ['hours.mon', 'hours', ['mon' => [['09:00', '24:00']]]],
    'a time is no string' => ['hours.mon', 'hours', ['mon' => [['09:00', 1000]]]],
    'a period ends before it starts' => ['hours.mon', 'hours', ['mon' => [['12:00', '09:00']]]],
    'a period is empty' => ['hours.mon', 'hours', ['mon' => [['09:00', '09:00']]]],
    'a later day is invalid' => ['hours.fri', 'hours', ['mon' => [['09:00', '10:00']], 'fri' => [['10:00', '09:00']]]],
]);

it('reports a missing hours key as an invalid value', function (): void {
    $config = eventTypeConfig();
    unset($config['hours']);

    expect(fn (): EventType => EventType::fromConfig('demo', $config))
        ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.demo.hours" must be an array');
});
