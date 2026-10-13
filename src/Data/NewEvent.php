<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Input for creating a timed event. No recurrence and no all-day: the demo use case needs neither.
 */
final readonly class NewEvent
{
    private const string WALL_CLOCK = 'Y-m-d\TH:i';

    /**
     * @param  string  $timezone  the IANA zone the wall-clock times are sent in
     * @param  list<Attendee>  $attendees  Cirrux mails each of them an invitation
     */
    public function __construct(
        public string $title,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $timezone,
        public ?string $description = null,
        public ?string $location = null,
        public ?string $url = null,
        public Transparency $transparency = Transparency::Opaque,
        public array $attendees = [],
    ) {
        throw_if($end->lessThanOrEqualTo($start), InvalidArgumentException::class, 'The end of an event must be after its start.');
    }

    /**
     * The request body as the Cirrux CLI sends it (cirrux-co/cli src/commands/calendar/events/event-body.ts):
     * a bare `YYYY-MM-DDTHH:MM` wall-clock time plus `time_zone`, resolved by the server.
     *
     * Cirrux gets no offset, so the wall-clock time must name one instant. Fail closed
     * instead of guessing: a start or end that has seconds, or that falls in the repeated
     * hour of the autumn DST change, is rejected.
     *
     * Times coming back from Cirrux without an offset in that repeated hour follow Carbon's
     * default (the first occurrence); the contract tests pin what Cirrux does there.
     *
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a bound cannot be sent unambiguously
     */
    public function toBody(): array
    {
        $body = [
            'title' => $this->title,
            'transparency' => $this->transparency->value,
            'start' => $this->bound($this->start),
            'end' => $this->bound($this->end),
        ];

        foreach (['description' => $this->description, 'location' => $this->location, 'url' => $this->url] as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }

        if ($this->attendees !== []) {
            $body['attendees'] = array_map(
                static fn (Attendee $attendee): array => array_filter(
                    ['email' => $attendee->email, 'name' => $attendee->name],
                    static fn (?string $value): bool => $value !== null,
                ),
                $this->attendees,
            );
        }

        return $body;
    }

    /**
     * @return array{date_time: string, time_zone: string}
     */
    private function bound(CarbonImmutable $instant): array
    {
        if ($instant->second !== 0 || $instant->microsecond !== 0) {
            throw new InvalidArgumentException(sprintf('Event times are sent per minute, but %s has seconds.', $instant->toIso8601ZuluString()));
        }

        $wallClock = $instant->setTimezone($this->timezone)->format(self::WALL_CLOCK);

        // Read the wall-clock time with the UTC offsets of the day before and after: when
        // both give a valid instant, the time occurs twice (autumn) and cannot be sent.
        $instants = [];

        foreach ([-1, 1] as $days) {
            $offset = $instant->addDays($days)->setTimezone($this->timezone)->utcOffset();
            $candidate = CarbonImmutable::parse($wallClock, 'UTC')->subMinutes($offset);

            if ($candidate->setTimezone($this->timezone)->format(self::WALL_CLOCK) === $wallClock) {
                $instants[$candidate->getTimestamp()] = true;
            }
        }

        if (count($instants) > 1) {
            throw new InvalidArgumentException(sprintf('%s is ambiguous in %s (%s occurs twice); pick a time outside the repeated hour.', $instant->toIso8601ZuluString(), $this->timezone, $wallClock));
        }

        return [
            'date_time' => $wallClock,
            'time_zone' => $this->timezone,
        ];
    }
}
