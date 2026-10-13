<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Availability;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Data\Slot;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Illuminate\Support\Collection;

/**
 * Working hours, rules and events in, free slots out. No cache, no HTTP, no config and no clock:
 * everything comes in through the parameters, so `slots()`, `isFree()` and `rejectionFor()`
 * cannot disagree about what a valid slot is.
 *
 * The grid is laid out in the wall-clock time of the event type and converted to instants
 * afterwards, so a slot keeps its local time across a DST change.
 */
final readonly class SlotCalculator
{
    private const array DAYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    private const string WALL_CLOCK = 'Y-m-d H:i';

    /**
     * Every free, valid slot whose start lies in [from, to), by start, in UTC.
     *
     * @param  iterable<CalendarEvent>  $events
     * @return Collection<int, Slot>
     */
    public function slots(EventType $type, CarbonImmutable $from, CarbonImmutable $to, iterable $events, CarbonImmutable $now): Collection
    {
        $blocked = $this->blockedRanges($type, $events);
        $slots = [];

        // Walk the local dates as plain dates (labelled UTC), so adding a day never meets a DST change.
        $day = CarbonImmutable::parse($from->setTimezone($type->timezone)->format('Y-m-d'), 'UTC');
        $lastDate = $to->setTimezone($type->timezone)->format('Y-m-d');

        while ($day->format('Y-m-d') <= $lastDate) {
            foreach ($type->hours[self::DAYS[$day->dayOfWeekIso]] ?? [] as [$opens, $closes]) {
                $closing = $this->minutes($closes);

                for ($minute = $this->minutes($opens); $minute + $type->duration <= $closing; $minute += $type->slotInterval) {
                    $start = $this->instantAt($type, $day->format('Y-m-d'), $minute);

                    if (! $start instanceof CarbonImmutable || $start->lessThan($from) || $start->greaterThanOrEqualTo($to)) {
                        continue;
                    }

                    if ($this->rejectionFor($type, $start, $now) instanceof SlotRejection || $this->conflicts($type, $start, $blocked)) {
                        continue;
                    }

                    // Keyed by instant: periods that overlap in the config must not hand out a slot twice.
                    $slots[$start->getTimestamp()] = new Slot($start, $start->addMinutes($type->duration));
                }
            }

            $day = $day->addDay();
        }

        ksort($slots);

        return new Collection(array_values($slots));
    }

    /**
     * Null when `$start` is a valid slot start: on the grid, inside the hours, far enough ahead,
     * not too far ahead, not in the past, and with a start and end that are one instant each.
     */
    public function rejectionFor(EventType $type, CarbonImmutable $start, CarbonImmutable $now): ?SlotRejection
    {
        if ($start->lessThan($now)) {
            return SlotRejection::InPast;
        }

        if ($start->lessThan($now->addMinutes($type->minNotice))) {
            return SlotRejection::TooSoon;
        }

        if ($start->greaterThanOrEqualTo($now->addHours($type->maxDaysAhead * 24))) {
            return SlotRejection::TooFarAhead;
        }

        $local = $start->setTimezone($type->timezone);

        if ($local->second !== 0 || $local->microsecond !== 0) {
            return SlotRejection::OffGrid;
        }

        $minute = $local->hour * 60 + $local->minute;
        $rejection = $this->placementRejection($type, $local->dayOfWeekIso, $minute);

        if ($rejection instanceof SlotRejection) {
            return $rejection;
        }

        $date = $local->format('Y-m-d');
        $startsAt = $this->instantAt($type, $date, $minute);
        $endsAt = $this->instantAt($type, $date, $minute + $type->duration);

        // The wall-clock end must exist once and be `duration` minutes of real time away: a slot
        // that spans a DST change would not be the length the event type promises.
        if (! $startsAt instanceof CarbonImmutable || ! $endsAt instanceof CarbonImmutable || $endsAt->getTimestamp() - $startsAt->getTimestamp() !== $type->duration * 60) {
            return SlotRejection::AmbiguousTime;
        }

        return null;
    }

    /**
     * @param  iterable<CalendarEvent>  $events
     */
    public function isFree(EventType $type, CarbonImmutable $start, iterable $events, CarbonImmutable $now): bool
    {
        return ! $this->rejectionFor($type, $start, $now) instanceof SlotRejection
            && ! $this->conflicts($type, $start, $this->blockedRanges($type, $events));
    }

    /**
     * What a slot starting at `$start` takes up, buffers included: [start, end).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function busyWindow(EventType $type, CarbonImmutable $start): array
    {
        return [
            $start->subMinutes($type->bufferBefore),
            $start->addMinutes($type->duration + $type->bufferAfter),
        ];
    }

    /**
     * The events `slots()` needs for [from, to): everything that can touch a busy window of a
     * slot starting there. One day wider on both sides than the busy windows alone, because an
     * all-day event is stored as UTC dates but blocks a local day, which can lie up to 14 hours
     * off its stored range.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function eventsWindow(EventType $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            $from->subMinutes($type->bufferBefore)->subHours(24),
            $to->addMinutes($type->duration + $type->bufferAfter)->addHours(24),
        ];
    }

    /**
     * Whether a slot starting at that wall-clock minute fits a period, and sits on its grid.
     */
    private function placementRejection(EventType $type, int $dayOfWeek, int $minute): ?SlotRejection
    {
        $fitsOffGrid = false;

        foreach ($type->hours[self::DAYS[$dayOfWeek]] ?? [] as [$opens, $closes]) {
            $opening = $this->minutes($opens);

            if ($minute < $opening || $minute + $type->duration > $this->minutes($closes)) {
                continue;
            }

            if (($minute - $opening) % $type->slotInterval === 0) {
                return null;
            }

            $fitsOffGrid = true;
        }

        return $fitsOffGrid ? SlotRejection::OffGrid : SlotRejection::OutsideHours;
    }

    /**
     * The instant of a local wall-clock time, or null when that time does not exist (spring)
     * or occurs twice (autumn). Same test as NewEvent: read the time with the UTC offsets of
     * the day before and after, and count how many distinct instants give it back.
     */
    private function instantAt(EventType $type, string $date, int $minute): ?CarbonImmutable
    {
        $wallClock = sprintf('%s %02d:%02d', $date, intdiv($minute, 60), $minute % 60);
        $label = CarbonImmutable::parse($wallClock, 'UTC');
        $instants = [];

        foreach ([-1, 1] as $days) {
            $offset = $label->addDays($days)->setTimezone($type->timezone)->utcOffset();
            $candidate = $label->subMinutes($offset);

            if ($candidate->setTimezone($type->timezone)->format(self::WALL_CLOCK) === $wallClock) {
                $instants[$candidate->getTimestamp()] = $candidate;
            }
        }

        $instants = array_values($instants);

        return count($instants) === 1 ? $instants[0] : null;
    }

    /**
     * Opaque events as [start, end) instants. All-day events block whole local days of the event
     * type: their stored UTC dates are only the date, read here as local midnights.
     *
     * @param  iterable<CalendarEvent>  $events
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function blockedRanges(EventType $type, iterable $events): array
    {
        $blocked = [];

        foreach ($events as $event) {
            if ($event->transparency !== Transparency::Opaque) {
                continue;
            }

            $blocked[] = $event->allDay
                ? [
                    CarbonImmutable::parse($event->start->utc()->format('Y-m-d'), $type->timezone)->utc(),
                    CarbonImmutable::parse($event->end->utc()->format('Y-m-d'), $type->timezone)->utc(),
                ]
                : [$event->start, $event->end];
        }

        return $blocked;
    }

    /**
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $blocked
     */
    private function conflicts(EventType $type, CarbonImmutable $start, array $blocked): bool
    {
        [$busyStart, $busyEnd] = $this->busyWindow($type, $start);

        foreach ($blocked as [$eventStart, $eventEnd]) {
            if ($busyStart->lessThan($eventEnd) && $eventStart->lessThan($busyEnd)) {
                return true;
            }
        }

        return false;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
