<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Availability\SlotCalculator;
use Codesmiths\CirruxCalendar\Availability\SlotRejection;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Data\Slot;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Illuminate\Support\Collection;

const CALCULATOR_ZONE = 'Europe/Amsterdam';

/**
 * Monday 19 and Friday 23 October 2026 are both summer time (CEST, UTC+2).
 *
 * @param  array<string, mixed>  $overrides
 */
function calculatorType(array $overrides = []): EventType
{
    return EventType::fromConfig('demo', array_merge([
        'calendar' => 'calendar-1',
        'title' => 'Demo',
        'duration' => 30,
        'buffer_before' => 10,
        'buffer_after' => 10,
        'slot_interval' => 30,
        'min_notice' => 0,
        'max_days_ahead' => 21,
        'timezone' => CALCULATOR_ZONE,
        'hours' => [
            'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
            'fri' => [['09:00', '12:00'], ['13:00', '17:00']],
        ],
    ], $overrides));
}

function calculatorLocal(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, CALCULATOR_ZONE);
}

/** The reference "now": Monday 12 October 2026, 08:00 local, a week before the first test day. */
function calculatorNow(): CarbonImmutable
{
    return calculatorLocal('2026-10-12 08:00');
}

function calculatorEvent(string $start, string $end, Transparency $transparency = Transparency::Opaque, bool $allDay = false): CalendarEvent
{
    return new CalendarEvent(
        id: 'event-'.$start,
        seriesId: null,
        title: null,
        start: $allDay ? CarbonImmutable::parse($start, 'UTC') : calculatorLocal($start)->utc(),
        end: $allDay ? CarbonImmutable::parse($end, 'UTC') : calculatorLocal($end)->utc(),
        allDay: $allDay,
        transparency: $transparency,
        attendees: [],
        isRecurring: false,
    );
}

/**
 * @param  Collection<int, Slot>  $slots
 * @return list<string>
 */
function calculatorStarts(Collection $slots, string $format = 'H:i'): array
{
    return $slots
        ->map(static fn (Slot $slot): string => $slot->start->setTimezone(CALCULATOR_ZONE)->format($format))
        ->values()
        ->all();
}

/**
 * @param  iterable<CalendarEvent>  $events
 * @return Collection<int, Slot>
 */
function calculatorSlots(EventType $type, string $from, string $to, iterable $events = [], ?CarbonImmutable $now = null): Collection
{
    return (new SlotCalculator)->slots($type, calculatorLocal($from), calculatorLocal($to), $events, $now ?? calculatorNow());
}

const CALCULATOR_FULL_DAY = [
    '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
    '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30',
];

it('hands out the slots of the working hours on Monday and Friday only', function (): void {
    $slots = calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-26 00:00');

    expect(calculatorStarts($slots, 'D H:i'))->toBe([
        ...array_map(static fn (string $time): string => 'Mon '.$time, CALCULATOR_FULL_DAY),
        ...array_map(static fn (string $time): string => 'Fri '.$time, CALCULATOR_FULL_DAY),
    ])
        ->and($slots->first()?->start->toIso8601ZuluString())->toBe('2026-10-19T07:00:00Z')
        ->and($slots->first()?->end->toIso8601ZuluString())->toBe('2026-10-19T07:30:00Z');
});

it('returns the slots in UTC and sorted by start, also when the periods are not', function (): void {
    $type = calculatorType(['hours' => ['mon' => [['13:00', '14:00'], ['09:00', '10:00']]]]);
    $slots = calculatorSlots($type, '2026-10-19 00:00', '2026-10-20 00:00');

    expect(calculatorStarts($slots))->toBe(['09:00', '09:30', '13:00', '13:30'])
        ->and($slots->every(static fn (Slot $slot): bool => $slot->start->getTimezone()->getName() === 'UTC' && $slot->end->getTimezone()->getName() === 'UTC'))->toBeTrue();
});

it('hands out a slot once when periods overlap, and nothing for a day with an empty list', function (): void {
    $type = calculatorType(['hours' => ['mon' => [['09:00', '10:00'], ['09:30', '10:30']], 'fri' => []]]);

    expect(calculatorStarts(calculatorSlots($type, '2026-10-19 00:00', '2026-10-24 00:00'), 'D H:i'))
        ->toBe(['Mon 09:00', 'Mon 09:30', 'Mon 10:00']);
});

it('lets a slot end exactly at the end of a period and not run through the break', function (): void {
    $morning = calculatorStarts(calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-19 12:30'));
    $long = calculatorStarts(calculatorSlots(calculatorType(['duration' => 45, 'buffer_before' => 0, 'buffer_after' => 0]), '2026-10-19 00:00', '2026-10-19 13:00'));

    // 11:30 + 30 minutes ends at 12:00 sharp; 11:30 + 45 minutes would run into the break.
    expect($morning)->toContain('11:30')->not->toContain('12:00')
        ->and($long)->toBe(['09:00', '09:30', '10:00', '10:30', '11:00']);
});

it('starts the grid at the start of each period', function (): void {
    $type = calculatorType(['slot_interval' => 40, 'buffer_before' => 0, 'buffer_after' => 0]);

    expect(calculatorStarts(calculatorSlots($type, '2026-10-19 00:00', '2026-10-20 00:00')))
        ->toBe(['09:00', '09:40', '10:20', '11:00', '13:00', '13:40', '14:20', '15:00', '15:40', '16:20']);
});

it('only returns slots that start inside [from, to)', function (): void {
    $slots = calculatorSlots(calculatorType(), '2026-10-19 09:00', '2026-10-19 10:00');

    expect(calculatorStarts($slots))->toBe(['09:00', '09:30'])
        ->and(calculatorSlots(calculatorType(), '2026-10-19 10:00', '2026-10-19 10:00'))->toBeEmpty()
        ->and(calculatorSlots(calculatorType(), '2026-10-20 00:00', '2026-10-19 00:00'))->toBeEmpty();
});

it('uses the local date of from and to, whatever their own zone', function (): void {
    // 22:30Z on Sunday 18 October is already Monday 00:30 in Amsterdam.
    $type = calculatorType(['hours' => ['mon' => [['00:00', '01:00']], 'sun' => [['23:00', '23:30']]], 'buffer_before' => 0, 'buffer_after' => 0]);
    $slots = (new SlotCalculator)->slots(
        $type,
        CarbonImmutable::parse('2026-10-18 22:00', 'UTC'),
        CarbonImmutable::parse('2026-10-19 00:00', 'UTC'),
        [],
        calculatorNow(),
    );

    expect(calculatorStarts($slots, 'D H:i'))->toBe(['Mon 00:00', 'Mon 00:30']);
});

it('makes the next free slot after a booking at 10:00 the one at 11:00', function (): void {
    // Duration 30, buffers 10/10, interval 30: 09:30, 10:00 and 10:30 all touch the booking's buffers.
    $slots = calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-19 12:00', [calculatorEvent('2026-10-19 10:00', '2026-10-19 10:30')]);

    expect(calculatorStarts($slots))->toBe(['09:00', '11:00', '11:30']);
});

it('keeps every half hour free when there are no buffers', function (): void {
    $type = calculatorType(['buffer_before' => 0, 'buffer_after' => 0]);
    $slots = calculatorSlots($type, '2026-10-19 00:00', '2026-10-19 12:00', [calculatorEvent('2026-10-19 10:00', '2026-10-19 10:30')]);

    expect(calculatorStarts($slots))->toBe(['09:00', '09:30', '10:30', '11:00', '11:30']);
});

it('puts the buffers around every opaque event, an overlap of one minute counts, touching does not', function (): void {
    $type = calculatorType(['buffer_before' => 0, 'buffer_after' => 10, 'slot_interval' => 10]);
    // The slot at 10:00 ends at 10:30 plus ten minutes of buffer; an event from 10:40 does not touch it.
    $touching = calculatorSlots($type, '2026-10-19 10:00', '2026-10-19 10:10', [calculatorEvent('2026-10-19 10:40', '2026-10-19 11:00')]);
    $overlapping = calculatorSlots($type, '2026-10-19 10:00', '2026-10-19 10:10', [calculatorEvent('2026-10-19 10:39', '2026-10-19 11:00')]);

    expect(calculatorStarts($touching))->toBe(['10:00'])
        ->and($overlapping)->toBeEmpty();
});

it('ignores transparent events', function (): void {
    $slots = calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-20 00:00', [
        calculatorEvent('2026-10-19 09:00', '2026-10-19 17:00', Transparency::Transparent),
        calculatorEvent('2026-10-19', '2026-10-20', Transparency::Transparent, allDay: true),
    ]);

    expect(calculatorStarts($slots))->toBe(CALCULATOR_FULL_DAY);
});

it('blocks a whole local day for an all-day event, and not the days around it', function (): void {
    $allDay = calculatorEvent('2026-10-19', '2026-10-20', allDay: true);
    $slots = calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-24 00:00', [$allDay]);

    expect(calculatorStarts($slots, 'D H:i'))->toBe(array_map(static fn (string $time): string => 'Fri '.$time, CALCULATOR_FULL_DAY));
});

it('blocks the local day of an all-day event from 00:00, not from the UTC midnight that stores it', function (): void {
    // In summer time the UTC midnight of 19 October is 02:00 local: a UTC reading would leave 00:00 to 01:30 free.
    $type = calculatorType(['hours' => ['sun' => [['00:00', '02:00']], 'mon' => [['00:00', '02:00']]], 'buffer_before' => 0, 'buffer_after' => 0]);
    $monday = calculatorEvent('2026-10-19', '2026-10-20', allDay: true);
    $sunday = calculatorEvent('2026-10-18', '2026-10-19', allDay: true);

    expect(calculatorSlots($type, '2026-10-19 00:00', '2026-10-20 00:00', [$monday]))->toBeEmpty()
        ->and(calculatorStarts(calculatorSlots($type, '2026-10-19 00:00', '2026-10-20 00:00', [$sunday])))->toBe(['00:00', '00:30', '01:00', '01:30'])
        ->and(calculatorSlots($type, '2026-10-18 00:00', '2026-10-19 00:00', [$sunday]))->toBeEmpty();
});

it('blocks every day of a multi-day all-day event', function (): void {
    $slots = calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-24 00:00', [calculatorEvent('2026-10-19', '2026-10-24', allDay: true)]);

    expect($slots)->toBeEmpty();
});

it('lets a multi-day event block its first afternoon and last morning', function (): void {
    $slots = calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-24 00:00', [calculatorEvent('2026-10-19 15:00', '2026-10-23 10:00')]);
    $mondays = array_filter(calculatorStarts($slots, 'D H:i'), static fn (string $slot): bool => str_starts_with($slot, 'Mon'));
    $fridays = array_filter(calculatorStarts($slots, 'D H:i'), static fn (string $slot): bool => str_starts_with($slot, 'Fri'));

    expect(array_values($mondays))->toBe(['Mon 09:00', 'Mon 09:30', 'Mon 10:00', 'Mon 10:30', 'Mon 11:00', 'Mon 11:30', 'Mon 13:00', 'Mon 13:30', 'Mon 14:00'])
        ->and(array_values($fridays)[0])->toBe('Fri 10:30');
});

it('counts an event that begins before from and runs into the window', function (): void {
    $slots = calculatorSlots(calculatorType(), '2026-10-19 10:00', '2026-10-19 12:00', [calculatorEvent('2026-10-19 09:00', '2026-10-19 10:20')]);

    expect(calculatorStarts($slots))->toBe(['10:30', '11:00', '11:30']);
});

it('accepts events from any iterable', function (): void {
    $events = (static function (): Generator {
        yield calculatorEvent('2026-10-19 10:00', '2026-10-19 10:30');
    })();

    expect(calculatorStarts(calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-19 12:00', $events)))->toBe(['09:00', '11:00', '11:30']);
});

it('holds back slots closer than min_notice and starts at exactly now plus the notice', function (): void {
    $now = calculatorLocal('2026-10-19 08:00');
    $type = calculatorType(['min_notice' => 120]);

    expect(calculatorStarts(calculatorSlots($type, '2026-10-19 00:00', '2026-10-19 12:00', [], $now)))->toBe(['10:00', '10:30', '11:00', '11:30']);
});

it('stops at max_days_ahead from now, in instants', function (): void {
    $now = calculatorLocal('2026-10-12 10:00');
    $type = calculatorType(['max_days_ahead' => 7]);

    // The horizon is Monday 19 October 10:00 local; a slot starting at 10:00 is already too far.
    expect(calculatorStarts(calculatorSlots($type, '2026-10-19 00:00', '2026-10-19 12:00', [], $now)))->toBe(['09:00', '09:30']);
});

it('hands out nothing in the past', function (): void {
    $now = calculatorLocal('2026-10-19 10:15');

    expect(calculatorStarts(calculatorSlots(calculatorType(), '2026-10-19 00:00', '2026-10-19 12:00', [], $now)))->toBe(['10:30', '11:00', '11:30']);
});

it('says why a start is rejected', function (string $start, string $now, ?SlotRejection $expected, array $overrides = []): void {
    $type = calculatorType($overrides);
    $rejection = (new SlotCalculator)->rejectionFor($type, calculatorLocal($start), calculatorLocal($now));

    expect($rejection)->toBe($expected);
})->with([
    'valid' => ['2026-10-19 09:00', '2026-10-12 08:00', null],
    'valid in the second period' => ['2026-10-19 16:30', '2026-10-12 08:00', null],
    'valid at the start of a later grid' => ['2026-10-19 13:40', '2026-10-12 08:00', null, ['slot_interval' => 40]],
    'between two grid points' => ['2026-10-19 09:15', '2026-10-12 08:00', SlotRejection::OffGrid],
    'seconds' => ['2026-10-19 09:00:30', '2026-10-12 08:00', SlotRejection::OffGrid],
    'grid of the first period in the second' => ['2026-10-19 13:30', '2026-10-12 08:00', null],
    'grid counted from the period start' => ['2026-10-19 13:30', '2026-10-12 08:00', SlotRejection::OffGrid, ['slot_interval' => 40]],
    'before the hours' => ['2026-10-19 08:30', '2026-10-12 08:00', SlotRejection::OutsideHours],
    'the break' => ['2026-10-19 12:00', '2026-10-12 08:00', SlotRejection::OutsideHours],
    'ending in the break' => ['2026-10-19 11:45', '2026-10-12 08:00', SlotRejection::OutsideHours],
    'ending after the hours' => ['2026-10-19 17:00', '2026-10-12 08:00', SlotRejection::OutsideHours],
    'a day without hours' => ['2026-10-20 10:00', '2026-10-12 08:00', SlotRejection::OutsideHours],
    'in the past' => ['2026-10-19 09:00', '2026-10-19 09:30', SlotRejection::InPast],
    'closer than the notice' => ['2026-10-19 09:30', '2026-10-19 09:00', SlotRejection::TooSoon, ['min_notice' => 60]],
    'exactly the notice away' => ['2026-10-19 10:00', '2026-10-19 09:00', null, ['min_notice' => 60]],
    'beyond the horizon' => ['2026-10-23 09:00', '2026-10-12 08:00', SlotRejection::TooFarAhead, ['max_days_ahead' => 7]],
    'exactly on the horizon' => ['2026-10-19 10:00', '2026-10-12 10:00', SlotRejection::TooFarAhead, ['max_days_ahead' => 7]],
    'just inside the horizon' => ['2026-10-19 09:30', '2026-10-12 10:00', null, ['max_days_ahead' => 7]],
    'the past wins over everything else' => ['2026-10-19 08:15', '2026-10-19 09:00', SlotRejection::InPast],
]);

it('reads an instant in any zone', function (): void {
    $start = CarbonImmutable::parse('2026-10-19 07:00', 'UTC')->setTimezone('America/New_York');

    expect((new SlotCalculator)->rejectionFor(calculatorType(), $start, calculatorNow()->utc()))->toBeNull();
});

it('checks isFree against the same rules and the events', function (): void {
    $calculator = new SlotCalculator;
    $type = calculatorType();
    $events = [calculatorEvent('2026-10-19 10:00', '2026-10-19 10:30')];

    expect($calculator->isFree($type, calculatorLocal('2026-10-19 09:00'), $events, calculatorNow()))->toBeTrue()
        ->and($calculator->isFree($type, calculatorLocal('2026-10-19 09:30'), $events, calculatorNow()))->toBeFalse()
        ->and($calculator->isFree($type, calculatorLocal('2026-10-19 11:00'), $events, calculatorNow()))->toBeTrue()
        ->and($calculator->isFree($type, calculatorLocal('2026-10-19 09:15'), [], calculatorNow()))->toBeFalse()
        ->and($calculator->isFree($type, calculatorLocal('2026-10-19 09:00'), [], calculatorLocal('2026-10-19 09:30')))->toBeFalse();
});

it('agrees between slots() and isFree() on every quarter of an hour', function (): void {
    $calculator = new SlotCalculator;
    $type = calculatorType(['min_notice' => 90, 'max_days_ahead' => 6]);
    $now = calculatorLocal('2026-10-19 09:10');
    $events = [
        calculatorEvent('2026-10-19 13:10', '2026-10-19 14:00'),
        calculatorEvent('2026-10-23', '2026-10-24', allDay: true),
        calculatorEvent('2026-10-19 15:00', '2026-10-19 15:30', Transparency::Transparent),
    ];
    $from = calculatorLocal('2026-10-19 00:00');
    $to = calculatorLocal('2026-10-26 00:00');

    $free = $calculator->slots($type, $from, $to, $events, $now)
        ->map(static fn (Slot $slot): int => $slot->start->getTimestamp())
        ->all();

    $checked = [];

    for ($start = $from; $start->lessThan($to); $start = $start->addMinutes(15)) {
        if ($calculator->isFree($type, $start, $events, $now)) {
            $checked[] = $start->getTimestamp();
        }
    }

    expect($free)->not->toBeEmpty()->and($checked)->toBe($free);
});

it('works out the busy window of a slot, buffers included', function (): void {
    [$from, $to] = (new SlotCalculator)->busyWindow(calculatorType(), calculatorLocal('2026-10-19 10:00'));

    expect($from->equalTo(calculatorLocal('2026-10-19 09:50')))->toBeTrue()
        ->and($to->equalTo(calculatorLocal('2026-10-19 10:40')))->toBeTrue();
});

it('works out the events window for a range, with room for the buffers and for all-day events', function (): void {
    [$from, $to] = (new SlotCalculator)->eventsWindow(calculatorType(), calculatorLocal('2026-10-19 00:00'), calculatorLocal('2026-10-26 00:00'));

    expect($from->equalTo(calculatorLocal('2026-10-18 00:00')->subMinutes(10)))->toBeTrue()
        ->and($to->equalTo(calculatorLocal('2026-10-27 00:00')->addMinutes(40)))->toBeTrue();
});
