# Laravel Cirrux Calendar

A Laravel package for the calendar API of [Cirrux](https://cirrux.co): log in, read your calendars and create or delete events. Cached availability and booking, so people outside your company can book a demo straight into a Cirrux calendar, are in progress.

The package only gives you methods. It ships no routes and no UI: the booking page, forms and emails belong to your app.

> [!WARNING]
> **Beta and unofficial.** Cirrux has not documented its calendar endpoints publicly yet. What this package uses comes from the source code of the open source [Cirrux CLI](https://github.com/cirrux-co/cli), and it can change without notice. This package is not made by or affiliated with Cirrux.
>
> Releases stay on **0.x** until the endpoints are documented, so a minor release may contain breaking changes. Pin a minor version (`^0.1`) and read the changelog before you upgrade.

## Requirements

- PHP 8.3 or higher
- Laravel 13
- A cache store that supports atomic locks and is shared between your web workers and the scheduler (Redis, database, Memcached or DynamoDB)
- A Cirrux account. The calendar endpoints only accept user-scoped OAuth tokens; a workspace API key gets `403 Forbidden`.

## Installation

```bash
composer require cdsmths/laravel-cirrux-calendar
```

Publish and run the migration. It creates the `cirrux_tokens` table, which stores the tokens with the refresh token encrypted.

```bash
php artisan vendor:publish --tag=cirrux-migrations
php artisan migrate
```

Publishing the config file is optional:

```bash
php artisan vendor:publish --tag=cirrux-config
```

## Configuration

```dotenv
CIRRUX_CACHE_STORE=redis      # must support locks and be shared
CIRRUX_CLIENT_ID=cirrux-cli   # see the note below
```

| Key | Default | Meaning |
| --- | --- | --- |
| `cirrux.base_url` | `https://api.cirrux.co/` | API host |
| `cirrux.client_id` | `cirrux-cli` | OAuth client used for the device flow |
| `cirrux.cache.store` | default store | Cache store for the week cache, the calendar list, locks and the rate limiter |
| `cirrux.cache.ttl` | `86400` | Seconds cached events are kept as "last known good"; at least `max_staleness` |
| `cirrux.cache.max_staleness` | `7200` | Seconds after a refresh that cached events are still trusted; older data is not served |
| `cirrux.cache.weeks_ahead` | `4` | Weeks a refresh fetches, counting from the current ISO week |
| `cirrux.cache.calendars_ttl` | `86400` | Seconds the calendar list stays cached |
| `cirrux.lock.timeout` | `10` | Seconds to wait for the lock of a calendar |
| `cirrux.rate_limit.per_minute` | `60` | Local limit, well below the 600/min that Cirrux allows per token |
| `cirrux.event_types.*.calendar` | none | UUID of the calendar that an event type uses; `cirrux:refresh` refreshes every calendar named here |

The package checks this config when your app boots and throws an `InvalidConfigurationException` that names the key. It rejects numbers that are out of range, a `ttl` below `max_staleness`, an unknown time zone, a `max_days_ahead` beyond `weeks_ahead * 7`, malformed working hours and, outside the `testing` environment, a cache store on the `array` or `null` driver (locks do not work between processes there).

> [!NOTE]
> Cirrux has no way to register your own OAuth client yet, so the package defaults to the client id of the Cirrux CLI. That is fine for your own account. We have asked Cirrux for an official route for third-party apps.

## Logging in

The package uses the OAuth device flow, so no callback route is needed:

```bash
php artisan cirrux:login
```

The command shows a URL and a code. Open the URL, enter the code and approve the access. The command waits until you do, then stores the tokens.

From then on the package refreshes the access token by itself: ahead of time when it is about to expire, and once more after a `401`. Cirrux rotates the refresh token on every refresh. The package takes a lock and reads the store again before it refreshes, so two processes never spend the same refresh token.

```bash
php artisan cirrux:logout      # deletes the stored tokens
```

## Usage

### Calendars

```bash
php artisan cirrux:calendars   # name, UUID and color of each calendar
```

This command always asks Cirrux and refreshes the cache. Use it to find the UUIDs you need in your config.

```php
use Codesmiths\CirruxCalendar\Facades\Cirrux;

$calendars = Cirrux::calendars(); // Collection<int, Calendar> with uuid, name, color
```

`Cirrux::calendars()` reads through the cache (`cirrux.cache.calendars_ttl`).

### Events

```php
use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Facades\Cirrux;

$events = Cirrux::calendar($uuid)->events()
    ->between(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-09'))
    ->get(); // Collection<int, CalendarEvent>

$event = Cirrux::calendar($uuid)->events()->create(new NewEvent(
    title: 'Demo',
    start: CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Amsterdam'),
    end: CarbonImmutable::parse('2026-11-02 09:30', 'Europe/Amsterdam'),
    timezone: 'Europe/Amsterdam',
    attendees: [new Attendee('jane@example.com', 'Jane')],
));

Cirrux::calendar($uuid)->events()->delete($event->id);
```

Good to know:

- **All times are UTC.** Every `CalendarEvent` holds `CarbonImmutable` instants in UTC. Convert them for display only.
- **`between()` matches on overlap.** An event belongs to the window `[from, to)` when it overlaps it, including one that starts before `from`. The package follows every page of results and removes duplicates.
- **Cirrux mails the attendees.** Each attendee gets an invitation when the event is created and a cancellation when it is deleted. You don't need to send confirmation emails yourself.
- **Times are per minute.** `NewEvent` rejects a start or end with seconds, an end that is not after the start, and a time in the repeated hour of a DST change. Cirrux receives a wall-clock time without an offset, so such a time would mean two different moments.
- **Deleting is idempotent.** An event that is already gone counts as deleted.
- **Writes are sent once.** Creating and deleting are never retried, so a request that did reach Cirrux cannot create a second event.

> [!IMPORTANT]
> `get()` reads from the week cache and never calls Cirrux, so it is safe on pages that visitors open. `fresh()` skips the cache and goes to the API: don't use it on visitor paths.

```php
$events = Cirrux::calendar($uuid)->events()->fresh()->between($from, $to)->get(); // asks Cirrux
```

### Caching

The package keeps the events of the coming weeks in your cache, one entry per calendar per ISO week (UTC). Visitors only ever read from there, so the number of API calls does not depend on how many people open your page.

- **Refreshed by a job, not by visitors.** `Cirrux::refresh()` fetches the weeks from the current ISO week up to `cirrux.cache.weeks_ahead` in one go (following every page). It takes the calendar lock first; that lock coordinates refreshes (and, later, bookings) with each other. The write-through of creating and deleting events does not take it, so a refresh that runs at the same moment can overwrite a write-through, or the other way around. That lasts until the next successful refresh; if refreshing keeps failing, reads fail closed once the data is older than `max_staleness`. Booking will do a fresh check against Cirrux, so this cannot cause a double booking. Only when all pages arrived are the weeks replaced, so a failure halfway (a `429`, a `5xx`) leaves the old cache in place.
- **Fail closed.** When the cache cannot vouch for a window, `get()` throws a `StaleCalendarException` instead of returning an empty list: the weeks were never fetched, have expired, are older than `cirrux.cache.max_staleness`, or the window lies outside the refreshed weeks. "No data" is never read as "no events".
- **Write-through.** Creating or deleting an event through the package updates the cached week right away, so your own changes usually show up without waiting for the next refresh (see the race described above).
- **Changes made in Cirrux itself show up after one poll.** An event you add, move or delete in Cirrux directly is only seen at the next refresh, so with a five minute schedule it can take up to five minutes.
- **A shared, lock-capable store.** The week cache, the locks and the rate limiter use `cirrux.cache.store`. It must support atomic locks and be shared between your web workers and the scheduler (Redis, database, Memcached or DynamoDB).

```php
Cirrux::refresh();            // every calendar named in cirrux.event_types
Cirrux::refresh($uuid);       // one calendar
Cirrux::forget();             // empty the cache of every configured calendar
Cirrux::forget($uuid);        // empty the cache of one calendar; reads fail closed until the next refresh
```

Without an argument `refresh()` tries every configured calendar and then throws the first `CirruxException` it caught. The failure events are dispatched for each calendar that failed.

### Scheduling

The package schedules nothing by itself. Add the refresh to your scheduler, for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cirrux:refresh')->everyFiveMinutes()->withoutOverlapping();
```

```bash
php artisan cirrux:refresh            # every calendar named in cirrux.event_types
php artisan cirrux:refresh <uuid>     # one calendar
```

The command prints one line per calendar and exits with a failure code when any of them could not be refreshed. Without configured calendars it warns and succeeds.

### Laravel events

The package dispatches its own events, so you can build on top of it without changing it: post to Slack, create a lead in your CRM, or raise an alert when the connection breaks.

| Event | When | Payload |
| --- | --- | --- |
| `LoggedIn` | `cirrux:login` succeeded | `expiresAt` |
| `LoggedOut` | `cirrux:logout` | — |
| `TokenRefreshed` | The access token was refreshed | `expiresAt` |
| `TokenRefreshFailed` | Refreshing failed for good; someone must run `cirrux:login` | `CirruxFailure` |
| `CalendarEventCreated` | An event was created through the package | `calendarUuid`, `CalendarEvent` |
| `CalendarEventDeleted` | An event was deleted through the package (also when it was already gone) | `calendarUuid`, `eventId` |
| `CalendarRefreshed` | A calendar was refreshed | `calendarUuid`, `from`, `to`, `eventCount`, `apiCalls` |
| `CalendarRefreshFailed` | The refresh failed; the previous cache normally stays in place, unless it was forgotten first (e.g. after deleting a recurring event), in which case reads stay fail closed | `calendarUuid`, `CirruxFailure` |
| `RateLimited` | Cirrux answered `429` or the local limiter refused a request | `retryAfter` |

All events live in `Codesmiths\CirruxCalendar\Events`. Their payloads are DTOs and scalars, so they are safe for queued listeners, and they never contain tokens. A failure travels as a `CirruxFailure` (exception class, message, status, retryAfter) and not as the exception itself, because an exception holds closures that PHP cannot serialize. Renaming or removing a payload field is a breaking change.

### Exceptions

Everything the package throws extends `Codesmiths\CirruxCalendar\Exceptions\CirruxException`:

| Exception | Meaning |
| --- | --- |
| `CirruxAuthException` | Not logged in, or the tokens can no longer be refreshed |
| `CirruxRequestException` | Cirrux refused the request or could not be reached; `status()` returns the HTTP status (0 when no response came back) |
| `RateLimitedException` | Cirrux or the local limiter says to slow down; `$retryAfter` holds the seconds to wait |
| `StaleCalendarException` | The week cache is missing, expired, older than `max_staleness` or does not cover the window; `$calendarUuid` holds the calendar |
| `InvalidConfigurationException` | The `cirrux` config cannot be used; thrown when the app boots and names the key |

### Replacing behaviour

Every action with side effects is a small class under `Codesmiths\CirruxCalendar\Actions` with a single `handle()` method, resolved from the container. To change one, bind your own implementation:

```php
$this->app->bind(\Codesmiths\CirruxCalendar\Actions\Events\CreateEvent::class, MyCreateEvent::class);
```

## Roadmap

| Release | Adds |
| --- | --- |
| 0.1 (this beta) | Device login, token refresh, calendars, reading, creating and deleting events |
| this release | Week cache refreshed by a scheduled job, `cirrux:refresh`, write-through, fail closed when data is stale, config validation |
| later | Availability: slots from working hours, buffers, minimum notice and time zones, including DST changes |
| later | Booking and cancelling with a per-calendar lock, a fresh check before booking, a `cirrux_bookings` table and reconciliation |

The full design is in [plan.md](plan.md) (in Dutch).

Out of scope, on purpose: booking pages, forms, reschedule links, meeting links, reminders, teams, round-robin and payments. Your app builds those on top of the package and its events.

## Testing

```bash
composer test              # lint, static analysis, type coverage and unit tests
composer test:lint         # Pint and Rector (dry run)
composer test:types        # Larastan, level max
composer test:type-coverage
composer test:unit         # Pest, requires exactly 100% code coverage
```

The unit and feature tests never make real HTTP calls.

### Contract tests

Because the API is undocumented, an opt-in suite runs against a real Cirrux account to notice when the API behaves differently from what the package assumes. It creates events in a test calendar and deletes them afterwards. Without credentials, the suite is skipped.

```bash
CIRRUX_TEST_CALENDAR=<uuid of an empty calendar> \
CIRRUX_REFRESH_TOKEN=<refresh token> \
composer test:contract
```

Use a calendar you don't use for anything else. Cirrux rotates the refresh token, so the token you pass works for one run only.

## Contributing

Issues and pull requests are welcome. Before you open a pull request:

- run `composer lint` and `composer test`, and keep coverage at 100%;
- follow the existing style: `declare(strict_types=1)`, `final` classes, `CarbonImmutable`, actions for side effects;
- if you notice the API behaving differently from the docblocks in `src/Http/Requests`, an issue with the request and response (tokens removed) helps a lot.

## Security

Please don't report security issues in public issues. Email thomas@trivle.com instead.

## License

The MIT License. See [LICENSE](LICENSE).
