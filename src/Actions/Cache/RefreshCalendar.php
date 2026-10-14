<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Cache;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Booking\ReconcileBookings;
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
use Throwable;

final readonly class RefreshCalendar
{
    /**
     * How long a crashed process can hold the lock before it is released on its own.
     */
    private const int LOCK_SECONDS = 120;

    public function __construct(
        private EventCache $cache,
        private CirruxConnector $connector,
        private LockProvider $locks,
        private ReconcileBookings $reconcile,
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
            /** @var array{CalendarRefreshed, Throwable|null} $result */
            $result = $this->locks
                ->lock('cirrux:lock:'.$calendarUuid, self::LOCK_SECONDS)
                ->block(Config::integer('cirrux.lock.timeout'), fn (): array => $this->refresh($calendarUuid));
        } catch (LockTimeoutException $exception) {
            $this->fail($calendarUuid, new CirruxRequestException('Timed out waiting for another process to refresh the calendar.', 0, $exception));
        } catch (CirruxException $exception) {
            $this->fail($calendarUuid, $exception);
        }

        [$refreshed, $reconciliationFailure] = $result;

        // Announced outside the lock, so a listener can take it. When the reconciliation failed, that
        // failure is what the caller gets: it wins over anything a listener throws.
        try {
            event($refreshed);
        } finally {
            if ($reconciliationFailure instanceof Throwable) {
                $this->rethrow($reconciliationFailure);
            }
        }
    }

    private function rethrow(Throwable $throwable): never
    {
        throw $throwable;
    }

    /**
     * @return array{CalendarRefreshed, Throwable|null} the announcement, and the failure of the reconciliation if it failed
     */
    private function refresh(string $calendarUuid): array
    {
        [$from, $to] = $this->cache->refreshWindow();

        // Cirrux matches the window on overlap, so an event that started before `from` comes along.
        $paginator = $this->connector->paginate(new ListEvents($calendarUuid, $from, $to, 'UTC'));

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

        $events = collect(array_values($events));

        $this->cache->replace($calendarUuid, $from, $to, $events, CarbonImmutable::now('UTC'));

        $refreshed = new CalendarRefreshed($calendarUuid, $from, $to, $events->count(), $apiCalls);

        // Still under the calendar lock, so booking and cancelling cannot interleave. A failure
        // here is a database error, not a Cirrux one, so it is not announced as a failed refresh
        // and not swallowed. The cache was replaced by then, so the refresh itself did succeed:
        // the caller announces CalendarRefreshed (outside the lock) and then throws the failure.
        try {
            $this->reconcile->handle($calendarUuid, $from, $to, $events);
        } catch (Throwable $throwable) {
            return [$refreshed, $throwable];
        }

        return [$refreshed, null];
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
