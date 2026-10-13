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

/**
 * Europe/Amsterdam changes clocks on Sunday 29 March 2026 (02:00 CET becomes 03:00 CEST, 01:00Z)
 * and on Sunday 25 October 2026 (03:00 CEST becomes 02:00 CET, 01:00Z).
 *
 * @param  array<string, mixed>  $overrides
 */
function dstType(array $overrides = []): EventType
{
    return EventType::fromConfig('demo', array_merge([
        'calendar' => null,
        'title' => 'Demo',
        'duration' => 30,
        'buffer_before' => 0,
        'buffer_after' => 0,
        'slot_interval' => 30,
        'min_notice' => 0,
        'max_days_ahead' => 365,
        'timezone' => 'Europe/Amsterdam',
        'hours' => ['sun' => [['01:00', '04:00']]],
    ], $overrides));
}

function dstNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-01-05 08:00', 'UTC');
}

/**
 * @param  list<CalendarEvent>  $events
 * @return Collection<int, Slot>
 */
function dstSlots(EventType $type, string $date, array $events = []): Collection
{
    $from = CarbonImmutable::parse($date, $type->timezone);

    return (new SlotCalculator)->slots($type, $from, $from->addDay()->startOfDay(), $events, dstNow());
}

/**
 * @param  Collection<int, Slot>  $slots
 * @return list<string>
 */
function dstLocalStarts(Collection $slots): array
{
    return $slots->map(static fn (Slot $slot): string => $slot->start->setTimezone('Europe/Amsterdam')->format('H:i'))->values()->all();
}

/**
 * @param  Collection<int, Slot>  $slots
 * @return list<string>
 */
function dstUtcStarts(Collection $slots): array
{
    return $slots->map(static fn (Slot $slot): string => $slot->start->toIso8601ZuluString('minute'))->values()->all();
}

function dstAllDay(string $start, string $end): CalendarEvent
{
    return new CalendarEvent('all-day', null, null, CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC'), true, Transparency::Opaque, [], false);
}

function dstReject(EventType $type, string $instant): ?SlotRejection
{
    return (new SlotCalculator)->rejectionFor($type, CarbonImmutable::parse($instant, 'UTC'), dstNow());
}

describe('summer time starts, Sunday 29 March 2026', function (): void {
    it('skips the slots whose start or end falls in the hour that does not exist', function (): void {
        $slots = dstSlots(dstType(), '2026-03-29');

        // 01:30 would end at 02:00, which the clocks skip; 02:00 and 02:30 do not exist at all.
        expect(dstLocalStarts($slots))->toBe(['01:00', '03:00', '03:30'])
            ->and(dstUtcStarts($slots))->toBe(['2026-03-29T00:00Z', '2026-03-29T01:00Z', '2026-03-29T01:30Z']);
    });

    it('gives every slot its full length in real time', function (): void {
        $slots = dstSlots(dstType(), '2026-03-29');

        expect($slots->every(static fn (Slot $slot): bool => $slot->start->diffInMinutes($slot->end, true) === 30.0))->toBeTrue();
    });

    it('skips a slot that straddles the change, even when both its wall-clock bounds exist', function (): void {
        // 01:30 to 03:00 on the clock is 90 minutes, but only 30 of them are real.
        $type = dstType(['duration' => 90, 'hours' => ['sun' => [['01:00', '05:00']]]]);

        expect(dstLocalStarts(dstSlots($type, '2026-03-29')))->toBe(['03:00', '03:30'])
            ->and(dstReject($type, '2026-03-29T00:30:00Z'))->toBe(SlotRejection::AmbiguousTime);
    });

    it('rejects the slot that ends on the missing hour and accepts the first one after it', function (): void {
        expect(dstReject(dstType(), '2026-03-29T00:30:00Z'))->toBe(SlotRejection::AmbiguousTime)
            ->and(dstReject(dstType(), '2026-03-29T00:00:00Z'))->toBeNull()
            ->and(dstReject(dstType(), '2026-03-29T01:00:00Z'))->toBeNull();
    });

    it('keeps 09:00 local on the grid: 08:00Z before the change, 07:00Z after it', function (): void {
        $type = dstType(['hours' => ['sun' => [['09:00', '10:00']], 'mon' => [['09:00', '10:00']]]]);
        $before = (new SlotCalculator)->slots($type, CarbonImmutable::parse('2026-03-22', 'Europe/Amsterdam'), CarbonImmutable::parse('2026-03-24', 'Europe/Amsterdam'), [], dstNow());
        $change = dstSlots($type, '2026-03-29');
        $after = dstSlots($type, '2026-03-30');

        expect(dstUtcStarts($before))->toBe(['2026-03-22T08:00Z', '2026-03-22T08:30Z', '2026-03-23T08:00Z', '2026-03-23T08:30Z'])
            ->and(dstUtcStarts($change))->toBe(['2026-03-29T07:00Z', '2026-03-29T07:30Z'])
            ->and(dstUtcStarts($after))->toBe(['2026-03-30T07:00Z', '2026-03-30T07:30Z']);
    });

    it('blocks an opaque event by its real instants on the day of the change', function (): void {
        $event = new CalendarEvent('busy', null, null, CarbonImmutable::parse('2026-03-29T01:00:00Z'), CarbonImmutable::parse('2026-03-29T01:30:00Z'), false, Transparency::Opaque, [], false);

        expect(dstLocalStarts(dstSlots(dstType(), '2026-03-29', [$event])))->toBe(['01:00', '03:30']);
    });
});

describe('winter time starts, Sunday 25 October 2026', function (): void {
    it('skips the slots whose start or end falls in the hour that occurs twice', function (): void {
        $slots = dstSlots(dstType(), '2026-10-25');

        // 01:30 would end at 02:00, which is on the clock twice; 02:00 and 02:30 are on it twice as well.
        expect(dstLocalStarts($slots))->toBe(['01:00', '03:00', '03:30'])
            ->and(dstUtcStarts($slots))->toBe(['2026-10-24T23:00Z', '2026-10-25T02:00Z', '2026-10-25T02:30Z']);
    });

    it('rejects an instant in either of the two repeated hours', function (): void {
        $type = dstType();

        expect(dstReject($type, '2026-10-24T23:30:00Z'))->toBe(SlotRejection::AmbiguousTime)
            ->and(dstReject($type, '2026-10-25T00:00:00Z'))->toBe(SlotRejection::AmbiguousTime)
            ->and(dstReject($type, '2026-10-25T00:30:00Z'))->toBe(SlotRejection::AmbiguousTime)
            ->and(dstReject($type, '2026-10-25T01:00:00Z'))->toBe(SlotRejection::AmbiguousTime)
            ->and(dstReject($type, '2026-10-25T01:30:00Z'))->toBe(SlotRejection::AmbiguousTime)
            ->and(dstReject($type, '2026-10-24T23:00:00Z'))->toBeNull()
            ->and(dstReject($type, '2026-10-25T02:00:00Z'))->toBeNull();
    });

    it('skips a slot that straddles the change, even when both its wall-clock bounds are unique', function (): void {
        // 01:30 CEST to 03:00 CET is 90 minutes on the clock and 150 in real time.
        $type = dstType(['duration' => 90, 'hours' => ['sun' => [['01:00', '05:00']]]]);

        expect(dstLocalStarts(dstSlots($type, '2026-10-25')))->toBe(['03:00', '03:30'])
            ->and(dstReject($type, '2026-10-24T23:30:00Z'))->toBe(SlotRejection::AmbiguousTime);
    });

    it('keeps 09:00 local on the grid: 07:00Z in summer, 08:00Z in winter', function (): void {
        $type = dstType(['hours' => ['mon' => [['09:00', '09:30']]]]);
        $summer = dstSlots($type, '2026-10-19');
        $winter = dstSlots($type, '2026-10-26');

        expect(dstUtcStarts($summer))->toBe(['2026-10-19T07:00Z'])
            ->and(dstUtcStarts($winter))->toBe(['2026-10-26T08:00Z']);
    });

    it('lays out a full day on the day of the change in local time', function (): void {
        $type = dstType(['hours' => ['sun' => [['08:00', '10:00']]]]);

        expect(dstUtcStarts(dstSlots($type, '2026-10-25')))->toBe(['2026-10-25T07:00Z', '2026-10-25T07:30Z', '2026-10-25T08:00Z', '2026-10-25T08:30Z']);
    });

    it('blocks the day of the change entirely for an all-day event, a day that lasts 25 hours', function (): void {
        $type = dstType(['hours' => ['sun' => [['00:00', '06:00']]]]);

        expect(dstSlots($type, '2026-10-25', [dstAllDay('2026-10-25', '2026-10-26')]))->toBeEmpty()
            ->and(dstLocalStarts(dstSlots($type, '2026-10-25', [dstAllDay('2026-10-24', '2026-10-25')])))->toBe(['00:00', '00:30', '01:00', '03:00', '03:30', '04:00', '04:30', '05:00', '05:30']);
    });
});

it('measures the buffers in real time on the day of a change', function (): void {
    // The event is 03:00 to 03:30 CET (02:00Z to 02:30Z). The 01:00 CEST slot is almost three real hours earlier.
    $event = new CalendarEvent('busy', null, null, CarbonImmutable::parse('2026-10-25T02:00:00Z'), CarbonImmutable::parse('2026-10-25T02:30:00Z'), false, Transparency::Opaque, [], false);

    expect(dstLocalStarts(dstSlots(dstType(['buffer_before' => 10, 'buffer_after' => 10]), '2026-10-25', [$event])))->toBe(['01:00']);
});
