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
    /**
     * @param  string  $timezone  the IANA zone stored as the event's own time zone
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
     * The request body of "Create an event" (cirrux.co/help/api/calendar). Each bound is an absolute
     * UTC instant plus `time_zone`: Cirrux reads an instant with `Z` as that instant and stores the
     * zone as the event's own, so a time in the repeated hour of a DST change is no longer ambiguous.
     *
     * @return array<string, mixed>
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
        return [
            'date_time' => $instant->utc()->toIso8601ZuluString(),
            'time_zone' => $this->timezone,
        ];
    }
}
