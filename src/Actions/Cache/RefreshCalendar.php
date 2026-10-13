<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Cache;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\CirruxFailure;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshed;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshFailed;
use Codesmiths\CirruxCalendar\Events\RateLimited;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Saloon\Http\Response;

final readonly class RefreshCalendar
{
    /**
     * Same lookback as EventQuery: Cirrux is asked a day earlier, so an event that starts
     * before the window and runs into it is found even if Cirrux matches on start.
     */
    private const int LOOKBACK_HOURS = 24;

    /**
     * How long a crashed process can hold the lock before it is released on its own.
     */
    private const int LOCK_SECONDS = 120;

    public function __construct(
        private EventCache $cache,
        private CirruxConnector $connector,
        private LockProvider $locks,
    ) {}

    /**
     * Fetches the coming weeks of a calendar and replaces the cache with them.
     *
     * Every page is fetched before anything is written, so a failure halfway leaves the
     * old cache as it was. The failure is announced and thrown again for the caller.
     *
     * @throws CirruxRequestException when a page fails or the calendar lock cannot be taken
     * @throws RateLimitedException when the rate limit is reached
     */
    public function handle(string $calendarUuid): void
    {
        try {
            /** @var CalendarRefreshed $refreshed */
            $refreshed = $this->locks
                ->lock('cirrux:lock:'.$calendarUuid, self::LOCK_SECONDS)
                ->block(Config::integer('cirrux.lock.timeout'), fn (): CalendarRefreshed => $this->refresh($calendarUuid));
        } catch (LockTimeoutException $exception) {
            $this->fail($calendarUuid, new CirruxRequestException('Timed out waiting for another process to refresh the calendar.', 0, $exception));
        } catch (CirruxException $exception) {
            $this->fail($calendarUuid, $exception);
        }

        // Phase 5: ReconcileBookings runs here, on the events that were just stored.

        event($refreshed);
    }

    private function refresh(string $calendarUuid): CalendarRefreshed
    {
        [$from, $to] = $this->cache->refreshWindow();

        $paginator = $this->connector->paginate(new ListEvents($calendarUuid, $from->subHours(self::LOOKBACK_HOURS), $to, 'UTC'));

        $events = [];
        $apiCalls = 0;

        /** @var Response $response */
        foreach ($paginator as $response) {
            $apiCalls++;

            /** @var Collection<int, CalendarEvent> $page */
            $page = $response->dtoOrFail();

            foreach ($page as $event) {
                if ($event->start->lessThan($to) && $event->end->greaterThan($from)) {
                    $events[$event->id] ??= $event;
                }
            }
        }

        $this->cache->replace($calendarUuid, $from, $to, collect(array_values($events)), CarbonImmutable::now('UTC'));

        return new CalendarRefreshed($calendarUuid, $from, $to, count($events), $apiCalls);
    }

    /**
     * @throws CirruxException always, the one it was given
     */
    private function fail(string $calendarUuid, CirruxException $exception): never
    {
        event(new CalendarRefreshFailed($calendarUuid, CirruxFailure::from($exception)));

        if ($exception instanceof RateLimitedException) {
            event(new RateLimited($exception->retryAfter));
        }

        throw $exception;
    }
}
