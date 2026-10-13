<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Events;

use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent as DeleteEventRequest;

final readonly class DeleteEvent
{
    public function __construct(private CirruxConnector $connector) {}

    /**
     * Deletes the event; Cirrux mails the attendees a cancellation. Idempotent: an event
     * that is already gone (404) counts as deleted.
     *
     * @throws CirruxRequestException for every failure except a 404
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(string $calendarUuid, string $eventId): void
    {
        try {
            $this->connector->send(new DeleteEventRequest($calendarUuid, $eventId));
        } catch (CirruxRequestException $cirruxRequestException) {
            throw_if($cirruxRequestException->status() !== 404, $cirruxRequestException);
        }

        event(new CalendarEventDeleted($calendarUuid, $eventId));
    }
}
