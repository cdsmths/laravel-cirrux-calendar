# Laravel Cirrux Calendar

![Laravel Cirrux Calendar](art/og-image.png)

A Laravel package for the calendar API of [Cirrux](https://cirrux.co): log in, read your calendars and create or delete events. Cached availability and booking are in, so people outside your company can book a demo straight into a Cirrux calendar.

The package only gives you methods. It ships no routes and no UI: the booking page, forms and emails belong to your app.

> [!WARNING]
> **Beta and unofficial.** The calendar endpoints are documented in the [Cirrux API reference](https://cirrux.co/help/api/calendar), but Cirrux has no way yet to register an app of your own: logging in borrows the OAuth client of the open source [Cirrux CLI](https://github.com/cirrux-co/cli). This package is not made by or affiliated with Cirrux.
>
> Releases stay on **0.x** while that is the case, so a minor release may contain breaking changes. Pin a minor version (`^0.1`) and read the changelog before you upgrade.

## Requirements

- PHP 8.3 or higher
- Laravel 13
- A cache store that supports atomic locks and is shared between your web workers and the scheduler (Redis, database, Memcached or DynamoDB)
- A Cirrux account. The calendar endpoints only accept user-scoped OAuth tokens; a workspace API key gets `403 Forbidden`.

## Getting started

From a fresh install to the first booking, in seven steps. The sections further down explain each part in detail.

### 1. Install the package and run the migrations

```bash
composer require cdsmths/laravel-cirrux-calendar
php artisan vendor:publish --tag=cirrux-migrations
php artisan vendor:publish --tag=cirrux-config
php artisan migrate
```

The migrations create `cirrux_tokens` (your login, with the refresh token encrypted) and `cirrux_bookings` (the bookings the package made). Publishing the config is optional, but you will want to change the working hours.

### 2. Point the package at a shared cache store

```dotenv
CIRRUX_CACHE_STORE=redis
```

The calendar is read from this cache and the locks live there, so it must support locks and be shared by your web workers and the scheduler: Redis, database, Memcached or DynamoDB. `array`, `file` and `null` do not work in production.

### 3. Log in to Cirrux

```bash
php artisan cirrux:login
```

Open the URL it shows, enter the code and approve. You do this once per environment; from then on the package refreshes the token by itself. Log in with the Cirrux account whose calendar people will book into.

### 4. Pick the calendar

```bash
php artisan cirrux:calendars
```

```text
+----------+-------------------+--------------------------------------+----------+---------+
| Name     | Mailbox           | UUID                                 | Writable | Color   |
+----------+-------------------+--------------------------------------+----------+---------+
| Calendar | sales@example.com | 9a8b7c6d-2222-4e5f-9a1b-000000000002 | yes      | #00CCAA |
| Holidays | -                 | 0f1e2d3c-5555-4a6b-8c7d-000000000005 | no       | -       |
+----------+-------------------+--------------------------------------+----------+---------+
```

Copy the UUID of a calendar that is writable. A calendar you can only view, or a subscription to an external feed, cannot take bookings.

```dotenv
CIRRUX_DEMO_CALENDAR=9a8b7c6d-2222-4e5f-9a1b-000000000002
CIRRUX_DEMO_URL=https://meet.example.com/demo   # optional: a video call link on every booking
```

### 5. Describe what can be booked

An **event type** in `config/cirrux.php` says what a booking is (30 minutes, called "Demo") and when it can happen (weekdays, 09:00 to 12:00 and 13:00 to 17:00, Amsterdam time). The package ships one called `demo`; change it or add your own next to it:

```php
'event_types' => [
    'demo' => [
        'calendar' => env('CIRRUX_DEMO_CALENDAR'),
        'title' => 'Demo',
        'duration' => 30,          // minutes
        'slot_interval' => 30,     // a slot starts every 30 minutes
        'buffer_before' => 10,     // free minutes needed before a slot
        'buffer_after' => 10,      // and after it
        'min_notice' => 60 * 24,   // book at least a day ahead
        'max_days_ahead' => 21,    // and at most three weeks
        'timezone' => 'Europe/Amsterdam',
        'hours' => [               // days left out cannot be booked
            'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
            'tue' => [['09:00', '12:00'], ['13:00', '17:00']],
            'wed' => [['09:00', '12:00'], ['13:00', '17:00']],
            'thu' => [['09:00', '12:00'], ['13:00', '17:00']],
            'fri' => [['09:00', '12:00'], ['13:00', '17:00']],
        ],
    ],
],
```

### 6. Schedule the refresh, and run it once

Slots are computed from a cached copy of the calendar, never from Cirrux directly. Without a refresh there is no copy, and **without a copy there are no slots**. Add the refresh to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cirrux:refresh')->everyFiveMinutes()->withoutOverlapping();
```

Make sure the scheduler runs (`php artisan schedule:work` locally, a cron entry in production), and fill the cache right away:

```bash
php artisan cirrux:refresh
```

### 7. Show the slots and book one

```php
use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Exceptions\InvalidSlotException;
use Codesmiths\CirruxCalendar\Exceptions\SlotUnavailableException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Illuminate\Http\Request;

final class DemoController
{
    public function index()
    {
        $slots = Cirrux::availability('demo')
            ->between(CarbonImmutable::now(), CarbonImmutable::now()->addWeeks(3))
            ->inTimezone('Europe/Amsterdam')
            ->slots();

        return view('demo.index', ['slots' => $slots]); // each slot has ->start and ->end
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $booking = Cirrux::book(
                'demo',
                CarbonImmutable::parse($data['start']),
                new Attendee($data['email'], $data['name']),
                title: 'Demo for '.$data['name'],
            );
        } catch (InvalidSlotException|SlotUnavailableException) {
            return back()->withErrors(['start' => 'That time is no longer available. Please pick another one.']);
        }

        return view('demo.booked', ['booking' => $booking]);
    }
}
```

Post `start` back exactly as the slot gave it to you, for example `$slot->start->toIso8601String()`. Cirrux mails the attendee the invitation, so there is no confirmation email to send. Cancel with `Cirrux::cancel($booking)`.

### How a slot comes about

This is what `slots()` does for every day in the window, with the `demo` event type above. Say it is Thursday 29 October, and on Monday 2 November there is a meeting in the calendar from 10:00 to 11:00.

1. **Working hours make a grid.** Monday has the periods 09:00 to 12:00 and 13:00 to 17:00. A slot starts every `slot_interval` (30) minutes and has to end inside its period, so the candidates are 09:00, 09:30, 10:00, 10:30, 11:00 and 11:30, then 13:00 up to 16:30. 11:30 still fits (it ends at 12:00), 12:00 does not. A day that is not in `hours` has no slots at all.
2. **Notice and horizon cut off the edges.** A slot has to start at least `min_notice` (24 hours) from now and less than `max_days_ahead` (21 days) from now. On Thursday afternoon, Friday morning is too soon; Monday is fine.
3. **Busy events and their buffers take slots away.** A slot needs `buffer_before` (10) free minutes before it and `buffer_after` (10) after it. The 09:30 slot would need 09:20 to 10:10 free, which runs into the meeting, so it is gone. So are 10:00, 10:30 and 11:00 (that one needs 10:50 to 11:40). 09:00 needs 08:50 to 09:40 and stays, and so does 11:30, which needs 11:20 to 12:10. Events marked "free" (transparent) block nothing; an all-day event that shows you as busy blocks the whole day.
4. **What remains is offered.** On that Monday: 09:00, 11:30, and every slot from 13:00 to 16:30.

`slots()` returns these as `Slot` objects with a UTC `start` and `end`; `inTimezone()` only changes how they are presented. `book()` checks the same rules again, and then checks the calendar with Cirrux itself, so a slot that was taken in the meantime is refused instead of booked twice.

**No slots?** Check these, in this order:

- **The cache is empty or too old.** `slots()` only answers from a refresh that is younger than `cirrux.cache.max_staleness` (two hours by default). Otherwise it returns nothing and dispatches `CalendarStale`. Run `php artisan cirrux:refresh` and check that the scheduler runs.
- **The window lies outside the bookable range.** The `min_notice` and `max_days_ahead` rules apply to the window you pass: a window of tomorrow only has no slots with the default notice of a day.
- **The day is not in `hours`,** or the periods are shorter than `duration`.
- **The calendar is simply full,** keeping the buffers in mind.

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
| `cirrux.cache.weeks_ahead` | `4` | Weeks a refresh fetches ahead, counted from now (it starts two days back); `max_days_ahead` must stay a week below this |
| `cirrux.cache.calendars_ttl` | `86400` | Seconds the calendar list stays cached |
| `cirrux.lock.timeout` | `10` | Seconds to wait for the lock of a calendar |
| `cirrux.rate_limit.per_minute` | `60` | Local limit, well below the 600/min that Cirrux allows per token |
| `cirrux.event_types.*.calendar` | none | UUID of the calendar that an event type uses; `cirrux:refresh` refreshes every calendar named here |
| `cirrux.event_types.*.url` | `CIRRUX_DEMO_URL` for `demo` | An http or https link put on every event a booking creates, for example a fixed video call room |
| `cirrux.event_types.*.location` | none | A location put on every event a booking creates |

The package checks this config when your app boots and throws an `InvalidConfigurationException` that names the key. It rejects numbers that are out of range, a `ttl` below `max_staleness`, an unknown time zone, a `max_days_ahead` beyond `(weeks_ahead - 1) * 7`, a `max_staleness` of more than a day (86400), a `buffer_before` of more than 1440 minutes, a `duration + buffer_after` of more than 1440 minutes, malformed working hours and, outside the `testing` environment, a cache store on the `array` or `null` driver (locks do not work between processes there).

The limits keep availability inside the cache: a refresh fetches `[now - 2 days, now + weeks_ahead weeks)`, and the events that a slot window needs reach at most a day plus the buffers beyond it. With the limits above, a read made within `max_staleness` of a refresh always fits in what that refresh fetched.

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
php artisan cirrux:calendars   # name, mailbox, UUID, whether it is writable, and color
```

This command always asks Cirrux and refreshes the cache. Use it to find the UUIDs you need in your config. A calendar that is linked to two of your mailboxes appears twice, with the same UUID; the mailbox column tells calendars with the same name apart.

```php
use Codesmiths\CirruxCalendar\Facades\Cirrux;

$calendars = Cirrux::calendars(); // Collection<int, Calendar> with uuid, name, color, mailboxAddress, canWrite
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
- **Times are sent as instants.** `NewEvent` sends each bound as a UTC instant together with the `timezone`, which Cirrux keeps as the event's own zone. A time in the repeated hour of a DST change is therefore unambiguous. `NewEvent` only rejects an end that is not after the start.
- **Deleting is idempotent.** An event that is already gone counts as deleted.
- **Writes are sent once.** Creating and deleting are never retried, so a request that did reach Cirrux cannot create a second event.

> [!IMPORTANT]
> `get()` reads from the week cache and never calls Cirrux, so it is safe on pages that visitors open. `fresh()` skips the cache and goes to the API: don't use it on visitor paths.

```php
$events = Cirrux::calendar($uuid)->events()->fresh()->between($from, $to)->get(); // asks Cirrux
```

### Caching

The package keeps the events of the coming weeks in your cache, one entry per calendar per ISO week (UTC). Visitors only ever read from there, so the number of API calls does not depend on how many people open your page.

- **Refreshed by a job, not by visitors.** `Cirrux::refresh()` fetches `[now - 2 days, now + cirrux.cache.weeks_ahead weeks)` in one go (following every page). It takes the calendar lock first; that lock coordinates refreshes and bookings with each other. The write-through of creating and deleting events does not take it, so a refresh that runs at the same moment can overwrite a write-through, or the other way around. That lasts until the next successful refresh; if refreshing keeps failing, reads fail closed once the data is older than `max_staleness`. Booking does a fresh check against Cirrux, so this cannot cause a double booking. Only when all pages arrived are the weeks replaced, so a failure halfway (a `429`, a `5xx`) leaves the old cache in place.
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

### Availability

An event type in `cirrux.event_types` describes what can be booked and when. `Cirrux::availability()` turns it, together with the cached events of its calendar, into free slots:

```php
use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Facades\Cirrux;

$slots = Cirrux::availability('demo')
    ->between(CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-16'))
    ->inTimezone('America/New_York')
    ->slots(); // Collection<int, Slot>, each with start and end

$free = Cirrux::availability('demo')->isFree(CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Amsterdam'));
```

The event type needs a `calendar`; without one `Cirrux::availability()` throws an `InvalidConfigurationException`. `inTimezone()` only changes how the slots are presented: the instants stay the same, and an unknown zone throws an `InvalidArgumentException`.

The rules:

- **The grid lives in local time.** Slots are laid out from the working hours in the `timezone` of the event type, every `slot_interval` minutes, and converted to instants afterwards. A slot keeps its local time across a DST change.
- **Slots around a DST change can disappear.** A slot whose start or end falls in the hour that does not exist (spring) or occurs twice (autumn), or that would not last `duration` minutes of real time, is not offered.
- **Buffers surround every opaque event.** A slot needs `buffer_before` free minutes before it and `buffer_after` after it. With 10 minute buffers and an event from 10:00 to 11:00, the slots from 09:30 up to and including 11:00 are gone; the last free slot before it is 09:00 (its buffer ends at 09:40) and the next after it is 11:30 (the buffer before it starts at 11:20).
- **Transparent events are ignored.** Only events that show you as busy block a slot.
- **An all-day event blocks the whole local day** of the event type, not the UTC date it is stored under.
- **Notice and horizon.** A slot must start at least `min_notice` minutes from now and less than `max_days_ahead` days from now. The window you pass is clipped to that; a window that is empty afterwards returns an empty collection without reading the cache.

> [!IMPORTANT]
> **Fail closed.** `slots()` reads the week cache and never calls Cirrux, so it is safe on visitor pages. When the cache cannot vouch for the requested window (the refresh metadata is missing or damaged, it is older than `max_staleness`, the window lies outside what the refresh covered, or a week bucket is missing) it returns an empty collection instead of guessing, and dispatches `CalendarStale`, at most once per `max_staleness` per calendar. Other exceptions are not caught.
>
> `isFree()` is the opposite: unless the start is not a valid slot (then it returns `false` without a call), it asks Cirrux for fresh events and lets `CirruxRequestException` and `RateLimitedException` through. Don't call it from visitor pages.

### Booking

```php
use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Facades\Cirrux;

$booking = Cirrux::book(
    'demo',
    CarbonImmutable::parse('2026-11-02 09:00', 'Europe/Amsterdam'),
    new Attendee('jane@example.com', 'Jane'),
    'Interested in the API', // optional notes, stored as the description of the event
    title: 'Demo for Acme Inc.', // optional; null, empty or whitespace uses the title of the event type
    url: 'https://meet.example.com/acme-demo', // optional video call link; falls back to the url of the event type
    location: 'Online', // optional; falls back to the location of the event type
); // Booking

Cirrux::cancel($booking);      // or Cirrux::cancel($booking->id)
```

`book()` does this, in order:

1. **Validates the start** against the event type: on the grid, inside the working hours, far enough ahead, not too far ahead, not in the past and not in an ambiguous DST hour. A start that fails throws an `InvalidSlotException` whose `$reason` is a `SlotRejection` (`in_past`, `too_soon`, `too_far_ahead`, `off_grid`, `outside_hours` or `ambiguous_time`). Nothing is sent to Cirrux.
2. **Takes the calendar lock** (the one refreshes use) and waits at most `cirrux.lock.timeout` seconds. When it cannot get it, it throws a `SlotUnavailableException` with reason `lock_timeout`.
3. **Checks the calendar with Cirrux itself**, not the cache, with buffers included. When the time is taken it throws a `SlotUnavailableException` with reason `busy`. This check is what rules out a double booking, whatever the cache holds.
4. **Dispatches `BookingCreating`.** A listener can hold the booking back by throwing a `BookingVetoedException`; nothing has been created at that point.
5. **Creates the event** in Cirrux, with the attendee, and Cirrux mails the attendee an invitation. The event gets the `title`, `url` and `location` you passed (trimmed), or those of the event type when you passed none. Cirrux puts them in the invitation, so a video call link reaches the attendee without extra mail. A `url` that is not http or https throws an `InvalidArgumentException` before anything is sent.

   The package does not create meetings. For a fixed room, set `url` on the event type. For a meeting per booking, create it in your app (with the Zoom or Google API, say) before you call `book()`, and pass its link.
6. **Stores the booking** in `cirrux_bookings`, releases the lock and dispatches `BookingCreated`.

If storing the booking fails (the database is down, say), the event is deleted from the calendar again and the original exception is thrown; a failure of that delete is reported with `report()` and does not hide the original one. If Cirrux creates the event but the answer never reaches you (a timeout), there is an event without a booking in Cirrux: the next refresh shows that time as busy.

Every refusal of the slot (an invalid start, a busy calendar, a lock timeout or a veto) dispatches `BookingRejected`, with the reason, after the lock is released. A wrong argument, such as a `url` that is not http or https, throws an `InvalidArgumentException` and dispatches nothing.

```php
Event::listen(BookingCreating::class, function (BookingCreating $event): void {
    if (isBlocked($event->attendee->email)) {
        throw new BookingVetoedException('This address cannot book.');
    }
});
```

`cancel()` takes the same lock, deletes the event (Cirrux mails the attendees a cancellation; an event that is already gone counts as deleted), marks the booking as cancelled by the user and dispatches `BookingCancelled`. It is idempotent: a booking that is already cancelled does nothing and dispatches nothing. An unknown id throws a `BookingNotFoundException`. When deleting the event fails, the booking stays as it was. When the lock cannot be taken in time it throws a `CirruxRequestException`.

`book()` calls Cirrux while it holds the lock, so don't call it where a request has to stay fast; run it in a controller action or a job.

#### Reconciliation

After every successful refresh the package compares the bookings in the refreshed window with the events Cirrux returned. A booking whose event is missing in two successive refreshes is marked as cancelled externally and `BookingCancelled` is dispatched with `CancelledBy::External`; one missing event is only noted, so a single incomplete answer cannot cancel your bookings. A booking whose event moved gets its new times and `BookingRescheduled` is dispatched. An event that is moved outside the refresh window (further than `weeks_ahead` weeks ahead) looks deleted and is treated like that after two refreshes.

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
| `CalendarStale` | Availability returned no slots because the cache cannot vouch for the requested window (see Availability); at most once per `max_staleness` per calendar | `calendarUuid`, `refreshedAt` (`null` when there is no usable refresh metadata) |
| `RateLimited` | Cirrux answered `429` or the local limiter refused a request | `retryAfter` |
| `BookingCreating` | A slot is valid and free and nothing has been written yet; throw a `BookingVetoedException` from a listener to refuse the booking | `eventType`, `Slot`, `Attendee`, `notes`, `title`, `url`, `location` |
| `BookingCreated` | A booking was stored (after the database commit) | `Booking` |
| `BookingRejected` | `book()` refused the slot (invalid start, busy, lock timeout or veto) | `eventType`, `start`, `Attendee`, `reason`: a `SlotRejection` value, `busy`, `lock_timeout` or `vetoed` |
| `BookingCancelled` | A booking was cancelled, by `cancel()` or by reconciliation (after the database commit) | `Booking`, `CancelledBy` (`user` or `external`) |
| `BookingRescheduled` | Reconciliation found that the event of a booking moved (after the database commit) | `Booking`, `previousStart`, `previousEnd` |

All events live in `Codesmiths\CirruxCalendar\Events`. Their payloads are DTOs and scalars, so they are safe for queued listeners, and they never contain tokens. A failure travels as a `CirruxFailure` (exception class, message, status, retryAfter) and not as the exception itself, because an exception holds closures that PHP cannot serialize. Renaming or removing a payload field is a breaking change.

### Exceptions

Everything the package throws extends `Codesmiths\CirruxCalendar\Exceptions\CirruxException`:

| Exception | Meaning |
| --- | --- |
| `CirruxAuthException` | Not logged in, or the tokens can no longer be refreshed |
| `CirruxRequestException` | Cirrux refused the request or could not be reached; `status()` returns the HTTP status (0 when no response came back) and `$error` the error code of Cirrux, such as `calendar_read_only`, `not_authorized` or `subscription_required` (null when there is none) |
| `RateLimitedException` | Cirrux or the local limiter says to slow down; `$retryAfter` holds the seconds to wait |
| `StaleCalendarException` | The week cache is missing, expired, older than `max_staleness` or does not cover the window; `$calendarUuid` holds the calendar |
| `InvalidConfigurationException` | The `cirrux` config cannot be used; thrown when the app boots and names the key |
| `InvalidSlotException` | The start is not a valid slot of the event type; `$reason` is a `SlotRejection` |
| `SlotUnavailableException` | The slot was valid but could not be booked; `$reason` is `busy` or `lock_timeout` |
| `BookingVetoedException` | Thrown by a `BookingCreating` listener to refuse a booking |
| `BookingNotFoundException` | No booking has that id; `$bookingId` holds it |

### Replacing behaviour

Every action with side effects is a small class under `Codesmiths\CirruxCalendar\Actions` with a single `handle()` method, resolved from the container. To change one, bind your own implementation:

```php
$this->app->bind(\Codesmiths\CirruxCalendar\Actions\Events\CreateEvent::class, MyCreateEvent::class);
```

## Roadmap

| Release | Adds |
| --- | --- |
| 0.1 (this beta) | Device login, token refresh, calendars, reading, creating and deleting events |
| this release | Week cache refreshed by a scheduled job, `cirrux:refresh`, write-through, fail closed when data is stale, config validation, availability: slots from working hours, buffers, minimum notice and time zones, including DST changes |
| this release | Booking and cancelling with a per-calendar lock, a fresh check before booking, a `cirrux_bookings` table and reconciliation |

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

An opt-in suite runs against a real Cirrux account to notice when the API behaves differently from its documentation or from what the package assumes. It creates events in a test calendar and deletes them afterwards. Without credentials, the suite is skipped.

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
- if you notice the API behaving differently from the [Cirrux API reference](https://cirrux.co/help/api/calendar) or the docblocks in `src/Http/Requests`, an issue with the request and response (tokens removed) helps a lot.

## Security

Please don't report security issues in public issues. Email thomas@codesmiths.nl instead.

## License

The MIT License. See [LICENSE](LICENSE).
