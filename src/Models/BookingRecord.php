<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Models;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Models\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The row behind a booking, for use inside the package only. Payloads and the public API
 * hand out a Booking, never this model.
 *
 * @internal
 *
 * @property string $id
 * @property string $event_type
 * @property string $calendar_uuid
 * @property string $event_id
 * @property CarbonImmutable $start_at
 * @property CarbonImmutable $end_at
 * @property string $attendee_email
 * @property string|null $attendee_name
 * @property string|null $notes
 * @property BookingStatus $status
 * @property CarbonImmutable|null $missing_since
 * @property CarbonImmutable|null $cancelled_at
 * @property CancelledBy|null $cancelled_by
 */
#[Unguarded]
#[Table(name: 'cirrux_bookings')]
final class BookingRecord extends Model
{
    use HasUuids;

    public function toBooking(): Booking
    {
        return new Booking(
            id: $this->id,
            calendarUuid: $this->calendar_uuid,
            eventId: $this->event_id,
            eventType: $this->event_type,
            start: $this->start_at,
            end: $this->end_at,
            attendee: new Attendee($this->attendee_email, $this->attendee_name),
            notes: $this->notes,
            status: $this->status,
            cancelledBy: $this->cancelled_by,
            cancelledAt: $this->cancelled_at,
        );
    }

    /**
     * @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_at' => UtcDateTime::class,
            'end_at' => UtcDateTime::class,
            'missing_since' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class,
            'status' => BookingStatus::class,
            'cancelled_by' => CancelledBy::class,
        ];
    }
}
