# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the Cirrux calendar endpoints are undocumented, releases stay on 0.x and a minor release may contain breaking changes.

## [Unreleased]

### Added

- Device login (`cirrux:login`), logout (`cirrux:logout`) and automatic, double-checked token refresh with rotating refresh tokens stored encrypted in `cirrux_tokens`.
- `Cirrux::calendars()` and `cirrux:calendars` to list the calendars of the logged in user.
- `Cirrux::calendar($uuid)->events()` to read, create and delete events, with cursor pagination and UTC normalisation.
- A week cache for events, refreshed by `cirrux:refresh` / `Cirrux::refresh()` and cleared by `Cirrux::forget()`, with write-through on create and delete. Reads fail closed with a `StaleCalendarException` when the cache cannot vouch for the window.
- Availability: `Cirrux::availability('demo')->between($from, $to)->slots()` and `isFree($start)`, with working hours in local wall-clock time, buffers around every busy event, minimum notice, a booking horizon and safe handling of DST changes.
- Laravel events for every meaningful step: `LoggedIn`, `LoggedOut`, `TokenRefreshed`, `TokenRefreshFailed`, `CalendarEventCreated`, `CalendarEventDeleted`, `CalendarRefreshed`, `CalendarRefreshFailed`, `CalendarStale` and `RateLimited`.
- Config validation when the app boots (`InvalidConfigurationException`).
- An opt-in contract test suite against a real Cirrux account (`composer test:contract`).
