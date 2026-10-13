<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Carbon\CarbonImmutable;

/**
 * A booking the package made, as the app sees it. All instants are UTC.
 */
final readonly class Booking
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public ?CarbonImmutable $cancelledAt;

    /**
     * The instants are normalized to UTC, whatever zone they come in.
     */
    public function __construct(
        public string $id,
        public string $calendarUuid,
        public string $eventId,
        public string $eventType,
        CarbonImmutable $start,
        CarbonImmutable $end,
        public Attendee $attendee,
        public ?string $notes,
        public BookingStatus $status,
        public ?CancelledBy $cancelledBy,
        ?CarbonImmutable $cancelledAt,
    ) {
        $this->start = $start->utc();
        $this->end = $end->utc();
        $this->cancelledAt = $cancelledAt?->utc();
    }

    /**
     * @return array{id: string, calendar_uuid: string, event_id: string, event_type: string, start: string, end: string, attendee: array{email: string, name: string|null}, notes: string|null, status: string, cancelled_by: string|null, cancelled_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'calendar_uuid' => $this->calendarUuid,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'attendee' => $this->attendee->toArray(),
            'notes' => $this->notes,
            'status' => $this->status->value,
            'cancelled_by' => $this->cancelledBy?->value,
            'cancelled_at' => $this->cancelledAt?->toIso8601String(),
        ];
    }
}
