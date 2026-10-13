<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Events;

use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Events\CalendarEventCreated;
use Codesmiths\CirruxCalendar\Events\RateLimited;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent as CreateEventRequest;

final readonly class CreateEvent
{
    public function __construct(
        private CirruxConnector $connector,
        private EventCache $cache,
    ) {}

    /**
     * Creates the event and writes it through to the week cache; Cirrux mails every attendee an invitation.
     *
     * The write-through takes no calendar lock: BookSlot (phase 5) holds that lock while it calls this
     * action, and Laravel locks are not re-entrant. A race with a running refresh can overwrite a
     * bucket, also for other events, so the new event may be missing from the cache until the next
     * successful refresh (at the latest until `max_staleness`, after which reads fail closed). The
     * fresh check in book() prevents a double booking.
     *
     * @throws CirruxRequestException when Cirrux refuses or cannot be reached
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(string $calendarUuid, NewEvent $event): CalendarEvent
    {
        try {
            /** @var CalendarEvent $created */
            $created = $this->connector->send(new CreateEventRequest($calendarUuid, $event))->dtoOrFail();
        } catch (RateLimitedException $rateLimitedException) {
            event(new RateLimited($rateLimitedException->retryAfter));

            throw $rateLimitedException;
        }

        $this->cache->add($calendarUuid, $created);

        event(new CalendarEventCreated($calendarUuid, $created));

        return $created;
    }
}
