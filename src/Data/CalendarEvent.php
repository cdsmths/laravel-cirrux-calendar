<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * One occurrence on a calendar. All instants are UTC.
 *
 * For an all-day event, start and end are midnight UTC of the dates Cirrux
 * returns; the end date is taken to be exclusive (iCalendar semantics).
 */
final readonly class CalendarEvent
{
    /** UTC with microseconds, so toArray() and fromArray() lose nothing. */
    private const string STORAGE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @param  string  $id  the occurrence id; for an expanded series occurrence `<series-uuid>_<recurrence-id>`
     * @param  list<Attendee>  $attendees
     */
    public function __construct(
        public string $id,
        public ?string $seriesId,
        public ?string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $allDay,
        public Transparency $transparency,
        public array $attendees,
        public bool $isRecurring,
    ) {}

    /**
     * Maps one event object of the Cirrux API (see CalendarEvent in cirrux-co/cli
     * src/commands/calendar/calendar-shared.ts). Used by every request that returns events.
     *
     * @param  array<array-key, mixed>  $event
     */
    public static function fromApi(array $event): self
    {
        $seriesId = $event['series_uuid'] ?? null;
        $recurrence = $event['recurrence'] ?? null;
        $transparency = $event['transparency'] ?? null;
        $start = Arr::array($event, 'start');

        $attendees = [];

        foreach (Arr::wrap($event['attendees'] ?? []) as $item) {
            $attendee = Arr::wrap($item);
            $email = $attendee['email'] ?? null;
            $name = $attendee['name'] ?? null;

            // An attendee without an address cannot be invited by mail, and we cannot identify them.
            if (is_string($email)) {
                $attendees[] = new Attendee($email, is_string($name) ? $name : null);
            }
        }

        $title = $event['title'] ?? null;

        return new self(
            id: Arr::string($event, 'id'),
            seriesId: is_string($seriesId) ? $seriesId : null,
            title: is_string($title) ? $title : null,
            start: self::instant($start),
            end: self::instant(Arr::array($event, 'end')),
            allDay: is_string($start['date'] ?? null),
            transparency: is_string($transparency) ? (Transparency::tryFrom($transparency) ?? Transparency::Opaque) : Transparency::Opaque,
            attendees: $attendees,
            isRecurring: is_string($seriesId) || (is_string($recurrence) && $recurrence !== ''),
        );
    }

    /**
     * @param  array<array-key, mixed>  $attributes  the result of toArray()
     */
    public static function fromArray(array $attributes): self
    {
        $seriesId = $attributes['series_id'] ?? null;
        $title = $attributes['title'] ?? null;

        return new self(
            id: Arr::string($attributes, 'id'),
            seriesId: is_string($seriesId) ? $seriesId : null,
            title: is_string($title) ? $title : null,
            start: CarbonImmutable::parse(Arr::string($attributes, 'start'))->utc(),
            end: CarbonImmutable::parse(Arr::string($attributes, 'end'))->utc(),
            allDay: Arr::boolean($attributes, 'all_day'),
            transparency: Transparency::from(Arr::string($attributes, 'transparency')),
            attendees: array_map(
                static fn (mixed $attendee): Attendee => Attendee::fromArray(Arr::wrap($attendee)),
                array_values(Arr::array($attributes, 'attendees')),
            ),
            isRecurring: Arr::boolean($attributes, 'is_recurring'),
        );
    }

    /**
     * @return array{id: string, series_id: string|null, title: string|null, start: string, end: string, all_day: bool, transparency: string, attendees: list<array{email: string, name: string|null}>, is_recurring: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'series_id' => $this->seriesId,
            'title' => $this->title,
            'start' => $this->start->utc()->format(self::STORAGE_FORMAT),
            'end' => $this->end->utc()->format(self::STORAGE_FORMAT),
            'all_day' => $this->allDay,
            'transparency' => $this->transparency->value,
            'attendees' => array_map(static fn (Attendee $attendee): array => $attendee->toArray(), $this->attendees),
            'is_recurring' => $this->isRecurring,
        ];
    }

    /**
     * `{date}` is an all-day bound. `{date_time, time_zone}` is an instant when the
     * string carries an offset or `Z`, and wall-clock time in `time_zone` when it does not.
     *
     * @param  array<array-key, mixed>  $bound
     */
    private static function instant(array $bound): CarbonImmutable
    {
        $date = $bound['date'] ?? null;

        if (is_string($date)) {
            return CarbonImmutable::parse($date, 'UTC')->startOfDay();
        }

        $zone = $bound['time_zone'] ?? null;

        return CarbonImmutable::parse(Arr::string($bound, 'date_time'), is_string($zone) ? $zone : 'UTC')->utc();
    }
}
