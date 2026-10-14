# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While Cirrux has no way to register a third-party app, releases stay on 0.x and a minor release may contain breaking changes.

## [0.1.5] - 2026-10-14

Brought in line with the [Cirrux API reference](https://cirrux.co/help/api/calendar), which now documents the calendar endpoints.

### Added

- `CirruxRequestException::$error` holds the error code Cirrux answered with (such as `calendar_read_only`, `not_authorized` or `subscription_required`), and the message names the code and the description.
- `Calendar` carries `mailboxAddress` and `canWrite`. `cirrux:calendars` shows both and warns that a calendar that is not writable cannot take bookings.
- A "Getting started" walkthrough in the README, with a worked example of how slots come about and what to check when there are none.

### Changed

- Event times are sent as UTC instants with the event's time zone instead of a wall-clock time. `NewEvent` therefore accepts times with seconds and times in the repeated hour of a DST change; only an end that is not after the start is still rejected. Availability still skips slots around a DST change.
- Reads ask Cirrux for the window itself instead of a day more: Cirrux documents that it matches the window on overlap.

## [0.1.4] - 2026-10-14

### Added

- A video call link and a location for bookings. An event type takes optional `url` (http or https; `CIRRUX_DEMO_URL` for `demo`) and `location`, and `Cirrux::book()` takes optional `url:` and `location:` that override them. Both are trimmed; null, empty or only whitespace falls back to the event type. Cirrux puts them in the invitation. A `url` that is not http or https throws an `InvalidArgumentException` before anything is sent.
- `EventType::isHttpUrl()`.

### Changed

- `BookingCreating` carries `url` and `location`, as optional last constructor arguments.

## [0.1.3] - 2026-10-14

### Changed

- A generic example title in the docs, and an Open Graph image in `art/`.

## [0.1.2] - 2026-10-14

### Added

- An optional `title` for a single booking: `Cirrux::book('demo', $start, $attendee, $notes, title: 'Demo for Acme Inc.')`. Null, an empty string or only whitespace falls back to the `title` of the event type; the title is trimmed.

### Changed

- `BookingCreating` carries the resolved `title` as a new last constructor argument and property.

## [0.1.1] - 2026-10-14

### Added

- Availability: `Cirrux::availability('demo')->between($from, $to)->inTimezone($tz)->slots()` and `isFree($start)`, with working hours in local wall-clock time, buffers around every busy event, minimum notice, a booking horizon and safe handling of DST changes. Slots are read from the cache only; when the cache cannot vouch for the window, `slots()` returns nothing and `CalendarStale` is dispatched.
- Booking: `Cirrux::book('demo', $start, $attendee, $notes)` and `Cirrux::cancel($booking)`, with a per-calendar lock, a fresh check against Cirrux, a veto through `BookingCreating`, and a compensating delete when the booking cannot be stored.
- A `cirrux_bookings` table (publish and run the migration) and reconciliation on every refresh: a booking whose event moved is rescheduled, and one whose event is missing in two consecutive refreshes is marked as cancelled externally.
- Events `CalendarStale`, `BookingCreating`, `BookingCreated`, `BookingRejected`, `BookingCancelled` and `BookingRescheduled`.
- Exceptions `InvalidSlotException`, `SlotUnavailableException`, `BookingVetoedException` and `BookingNotFoundException`.

### Changed

- The refresh now covers `[now - 2 days, now + weeks_ahead weeks)` instead of starting at the current ISO week.
- Config validation is stricter: `max_days_ahead <= (weeks_ahead - 1) * 7`, `buffer_before <= 1440`, `duration + buffer_after <= 1440` and `max_staleness <= 86400`, plus every field of an event type.
- `DeleteEvent::handle()` takes an optional `$refreshSeries` flag and returns whether a series refresh is still needed.

## [0.1.0] - 2026-10-13

### Added

- Device login (`cirrux:login`), logout (`cirrux:logout`) and automatic, double-checked token refresh with rotating refresh tokens stored encrypted in `cirrux_tokens`.
- `Cirrux::calendars()` and `cirrux:calendars` to list the calendars of the logged in user.
- `Cirrux::calendar($uuid)->events()` to read, create and delete events, with cursor pagination and UTC normalisation.
- A week cache for events, refreshed by `cirrux:refresh` / `Cirrux::refresh()` and cleared by `Cirrux::forget()`, with write-through on create and delete. Reads fail closed with a `StaleCalendarException` when the cache cannot vouch for the window.
- Laravel events `LoggedIn`, `LoggedOut`, `TokenRefreshed`, `TokenRefreshFailed`, `CalendarEventCreated`, `CalendarEventDeleted`, `CalendarRefreshed`, `CalendarRefreshFailed` and `RateLimited`.
- Config validation when the app boots (`InvalidConfigurationException`).
- An opt-in contract test suite against a real Cirrux account (`composer test:contract`).
