<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Events;

use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent as CreateEventRequest;

final readonly class CreateEvent
{
    public function __construct(private CirruxConnector $connector) {}

    /**
     * Creates the event; Cirrux mails every attendee an invitation.
     *
     * @throws CirruxRequestException when Cirrux refuses or cannot be reached
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(string $calendarUuid, NewEvent $event): CalendarEvent
    {
        /** @var CalendarEvent $created */
        $created = $this->connector->send(new CreateEventRequest($calendarUuid, $event))->dtoOrFail();

        event(new CalendarEventCreated($calendarUuid, $created));

        return $created;
    }
}
