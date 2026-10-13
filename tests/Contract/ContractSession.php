<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Tests\Contract;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;
use RuntimeException;
use Saloon\Exceptions\Request\RequestException;

/**
 * Shared state of the contract suite: the credentials from the environment, the token pair
 * and the events a test created.
 *
 * Every test boots a fresh application with an empty database, but Cirrux rotates the refresh
 * token on every refresh. The rotated pair is therefore kept in this process between tests;
 * only the first test of a run uses the refresh token from the environment, which is dead
 * afterwards.
 */
final class ContractSession
{
    public const string ZONE = 'Europe/Amsterdam';

    /**
     * Every title starts with this; the sweep deletes whatever matches, also leftovers of earlier runs.
     */
    private const string TITLE_MARKER = '[contract-test';

    private const int DELETE_ATTEMPTS = 3;

    private static ?Tokens $tokens = null;

    private static ?string $runId = null;

    private static ?CarbonImmutable $anchor = null;

    /**
     * The time windows of this test, in which the sweep looks for leftover events.
     *
     * @var list<array{CarbonImmutable, CarbonImmutable}>
     */
    private static array $windows = [];

    /**
     * @var list<string>
     */
    private static array $created = [];

    public static function skipReason(): ?string
    {
        $missing = array_values(array_filter(
            ['CIRRUX_TEST_CALENDAR', 'CIRRUX_REFRESH_TOKEN'],
            static fn (string $name): bool => in_array(getenv($name), [false, ''], true),
        ));

        return $missing === [] ? null : 'The contract suite needs '.implode(' and ', $missing).'.';
    }

    /**
     * Seeds the token store: the access token is expired, so the connector refreshes it itself.
     */
    public static function begin(): void
    {
        if (self::skipReason() !== null) {
            return;
        }

        self::$created = [];
        self::$windows = [];
        // One anchor per test: a test that runs across midnight must not see its days shift.
        self::$anchor = CarbonImmutable::now('UTC')->addDays(30)->startOfDay();

        resolve(TokenStore::class)->put(self::$tokens ?? new Tokens(
            accessToken: 'expired',
            refreshToken: self::env('CIRRUX_REFRESH_TOKEN'),
            expiresAt: CarbonImmutable::now('UTC')->subMinute(),
        ));
    }

    /**
     * Deletes every event the test created, also after a failed assertion, and keeps the
     * (possibly rotated) token pair for the next test.
     *
     * An id only leaves the list once Cirrux said 2xx or 404. Afterwards a sweep deletes every
     * "[contract-test" event in the windows the test used: events whose create response had
     * no usable id, and leftovers of runs that were aborted.
     */
    public static function finish(): void
    {
        if (self::skipReason() !== null) {
            return;
        }

        $failures = [];

        try {
            foreach (self::$created as $index => $id) {
                try {
                    self::remove($id);
                    unset(self::$created[$index]);
                } catch (CirruxException $exception) {
                    $failures[] = sprintf('%s (%s)', $id, $exception->getMessage());
                }
            }

            $failures = [...$failures, ...self::sweep()];
        } finally {
            self::$created = array_values(self::$created);
            self::$tokens = resolve(TokenStore::class)->get() ?? self::$tokens;
        }

        throw_if($failures !== [], RuntimeException::class, 'Could not delete the contract-test events: '.implode(', ', $failures));
    }

    public static function calendar(): string
    {
        return self::env('CIRRUX_TEST_CALENDAR');
    }

    public static function connector(): CirruxConnector
    {
        return resolve(CirruxConnector::class);
    }

    public static function title(string $what): string
    {
        self::$runId ??= bin2hex(random_bytes(3));

        return sprintf('%s %s] %s', self::TITLE_MARKER, self::$runId, $what);
    }

    /**
     * An instant `$days` days after the anchor of this test (a day 30 days from the start of
     * the test) at `$hour:$minute` UTC. Whole minutes, and never in the repeated hour of the
     * autumn change (that is 00:00-01:00 UTC), so NewEvent accepts it.
     */
    public static function at(int $days, int $hour, int $minute = 0): CarbonImmutable
    {
        $anchor = self::$anchor ?? throw new RuntimeException('ContractSession::begin() has not run.');

        return $anchor->addDays($days)->setTime($hour, $minute)->startOfMinute();
    }

    public static function event(string $what, CarbonImmutable $start, int $minutes = 30, Transparency $transparency = Transparency::Opaque): NewEvent
    {
        // Room for the three days of the test series; the sweep looks here.
        self::watch($start->subDay(), $start->addDays(3));

        return new NewEvent(
            title: self::title($what),
            start: $start,
            end: $start->addMinutes($minutes),
            timezone: self::ZONE,
            transparency: $transparency,
        );
    }

    /**
     * Creates the event through the package's own request and returns the raw response body.
     *
     * @return array<array-key, mixed>
     */
    public static function createRaw(NewEvent $event): array
    {
        $body = self::connector()->send(new CreateEvent(self::calendar(), $event))->array();
        self::track($body);

        return $body;
    }

    public static function create(NewEvent $event): CalendarEvent
    {
        return CalendarEvent::fromApi(self::createRaw($event));
    }

    /**
     * Creates an event from a hand-written body.
     *
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    public static function createFromBody(array $body): array
    {
        $response = self::connector()->send(new RawCreateEvent(self::calendar(), $body))->array();
        self::track($response);

        return $response;
    }

    /**
     * A recurring event: a body NewEvent cannot express. Sent as an RRULE string, a guess
     * from the CLI's `--recurrence`.
     *
     * @return array<array-key, mixed>
     */
    public static function createSeries(string $what, CarbonImmutable $start): array
    {
        return self::createFromBody([
            ...self::event($what, $start)->toBody(),
            'recurrence' => 'RRULE:FREQ=DAILY;COUNT=3',
        ]);
    }

    /**
     * Registers a created event for deletion. Looks in `data` too, so a wrapped response
     * still gets cleaned up while the shape test reports the surprise.
     *
     * @param  array<array-key, mixed>  $body
     */
    public static function track(array $body): void
    {
        $event = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $id = $event['event_uuid'] ?? $event['id'] ?? null;

        if (is_string($id)) {
            self::$created[] = $id;
        }
    }

    /**
     * One page of the raw list response.
     *
     * @param  array<string, string|int>  $extra  more query parameters, e.g. `limit` or `cursor`
     * @return array<array-key, mixed>
     */
    public static function list(CarbonImmutable $from, CarbonImmutable $to, string $timezone = self::ZONE, array $extra = []): array
    {
        self::watch($from, $to);

        return self::connector()->send(new RawListEvents(self::calendar(), [
            'time_min' => $from->utc()->toIso8601ZuluString(),
            'time_max' => $to->utc()->toIso8601ZuluString(),
            'timezone' => $timezone,
            ...$extra,
        ]))->array();
    }

    /**
     * The raw event objects of a list page.
     *
     * @param  array<array-key, mixed>  $page
     * @return list<array<array-key, mixed>>
     */
    public static function items(array $page): array
    {
        return array_values(array_filter(
            Arr::wrap($page['data'] ?? []),
            is_array(...),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $page
     * @return list<string>
     */
    public static function ids(array $page): array
    {
        return array_values(array_filter(
            array_map(static fn (array $item): mixed => $item['id'] ?? null, self::items($page)),
            is_string(...),
        ));
    }

    /**
     * The raw item of a list page with this `id`.
     *
     * @param  array<array-key, mixed>  $page
     * @return array<array-key, mixed>|null
     */
    public static function find(array $page, string $id): ?array
    {
        foreach (self::items($page) as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Midnight (Europe/Amsterdam) of the next last Sunday of October, at least two days away.
     */
    public static function nextAutumnChange(): CarbonImmutable
    {
        $now = CarbonImmutable::now(self::ZONE);

        for ($year = $now->year; ; $year++) {
            $change = CarbonImmutable::create($year, 10, 1, 0, 0, 0, self::ZONE)->lastOfMonth(CarbonInterface::SUNDAY)->startOfDay();

            if ($change->greaterThan($now->addDays(2))) {
                return $change;
            }
        }
    }

    private static function watch(CarbonImmutable $from, CarbonImmutable $to): void
    {
        self::$windows[] = [$from, $to];
    }

    /**
     * Deletes every "[contract-test" event in the watched windows.
     *
     * @return list<string> what could not be deleted
     */
    private static function sweep(): array
    {
        $failures = [];
        $swept = [];

        foreach (self::$windows as [$from, $to]) {
            try {
                $events = self::connector()
                    ->paginate(new ListEvents(self::calendar(), $from, $to, self::ZONE))
                    ->collect();

                foreach ($events as $event) {
                    // The series uuid removes the whole series; an occurrence id only one occurrence.
                    $id = $event->seriesId ?? $event->id;

                    if (! str_starts_with((string) $event->title, self::TITLE_MARKER) || isset($swept[$id])) {
                        continue;
                    }

                    $swept[$id] = true;
                    self::remove($id);
                }
            } catch (CirruxException $exception) {
                $failures[] = sprintf('sweep %s - %s (%s)', $from->toIso8601ZuluString(), $to->toIso8601ZuluString(), $exception->getMessage());
            }
        }

        return $failures;
    }

    /**
     * Deletes one event. 2xx and 404 are done; anything else is retried a few times,
     * waiting for `Retry-After` when Cirrux sent one.
     *
     * @throws CirruxException when it still fails after the last attempt
     */
    private static function remove(string $id): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                self::connector()->send(new DeleteEvent(self::calendar(), $id));

                return;
            } catch (RateLimitedException $exception) {
                $wait = $exception->retryAfter;
            } catch (CirruxRequestException $exception) {
                if ($exception->status() === 404) {
                    return;
                }

                $previous = $exception->getPrevious();
                $retryAfter = $previous instanceof RequestException ? $previous->getResponse()->header('Retry-After') : null;
                $wait = is_numeric($retryAfter) ? (int) $retryAfter : 1;
            }

            throw_if($attempt >= self::DELETE_ATTEMPTS, $exception);

            Sleep::for(max(1, min($wait, 60)))->seconds();
        }
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : throw new RuntimeException(sprintf('%s is not set.', $name));
    }
}
