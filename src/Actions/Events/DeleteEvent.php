<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Events;

use Codesmiths\CirruxCalendar\Actions\Cache\ForgetCalendar;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Events\CalendarEventDeleted;
use Codesmiths\CirruxCalendar\Events\RateLimited;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent as DeleteEventRequest;

final readonly class DeleteEvent
{
    public function __construct(
        private CirruxConnector $connector,
        private EventCache $cache,
        private ForgetCalendar $forgetCalendar,
        private RefreshCalendar $refreshCalendar,
    ) {}

    /**
     * Deletes the event and writes that through to the week cache; Cirrux mails the attendees a
     * cancellation. Idempotent: an event that is already gone (404) counts as deleted.
     *
     * Deleting a series (or an occurrence of one) can remove occurrences we cannot name, so the
     * calendar is forgotten and refreshed at once. A refresh that fails does not undo the delete:
     * the cache stays forgotten, so every read fails closed until the next refresh succeeds.
     * Anything else is removed from the buckets directly.
     *
     * The write-through takes no calendar lock: BookSlot (phase 5) holds that lock while it calls
     * this action, and Laravel locks are not re-entrant. A race with a running refresh can overwrite
     * a bucket, also for other events: the deleted event may stay in the cache, or an unrelated event
     * may drop out of it, until the next successful refresh (at the latest until `max_staleness`,
     * after which reads fail closed). The fresh check in book() prevents a double booking.
     *
     * @throws CirruxRequestException for every failure except a 404
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(string $calendarUuid, string $eventId): void
    {
        $cached = $this->cache->find($calendarUuid, $eventId);

        try {
            $this->connector->send(new DeleteEventRequest($calendarUuid, $eventId));
        } catch (CirruxRequestException $cirruxRequestException) {
            throw_if($cirruxRequestException->status() !== 404, $cirruxRequestException);
        } catch (RateLimitedException $rateLimitedException) {
            event(new RateLimited($rateLimitedException->retryAfter));

            throw $rateLimitedException;
        }

        if ($cached instanceof CalendarEvent && ($cached->isRecurring || $cached->seriesId === $eventId)) {
            $this->forgetAndRefresh($calendarUuid);
        } else {
            $this->cache->remove($calendarUuid, $eventId, $cached);
        }

        event(new CalendarEventDeleted($calendarUuid, $eventId));
    }

    private function forgetAndRefresh(string $calendarUuid): void
    {
        $this->forgetCalendar->handle($calendarUuid);

        try {
            $this->refreshCalendar->handle($calendarUuid);
        } catch (CirruxException) {
            // RefreshCalendar has announced the failure; the cache stays forgotten and fails closed.
        }
    }
}
