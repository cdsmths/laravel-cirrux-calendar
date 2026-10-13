# laravel-cirrux-calendar — plan

Laravel-package om met de API van [Cirrux](https://cirrux.co) te praten, met als eerste doel: externen een demo laten inplannen die direct in een Cirrux-agenda landt. Cirrux heeft (nog) geen eigen boekingsfunctie à la Cal.com.

Status: ontwerp. Nog geen code.

> **⚠️ Experimenteel package.** De agenda-endpoints van Cirrux zijn (nog) niet publiek gedocumenteerd. Wat dit package gebruikt is afgeleid uit de broncode van de open source Cirrux CLI en kan zonder aankondiging veranderen. Het package is niet officieel en niet verbonden aan Cirrux.
>
> Consequenties:
> - We blijven op **0.x-versies** zolang de endpoints ongedocumenteerd zijn. Minor releases kunnen breaking changes bevatten.
> - De README opent met dezelfde waarschuwing, en `composer.json` krijgt de keyword `experimental`.
> - Een opt-in contract-testsuite tegen een echt Cirrux-account signaleert wanneer de API afwijkt van wat we verwachten.
> - Zodra Cirrux de endpoints documenteert (of bevestigt dat ze stabiel zijn), toetsen we de implementatie daaraan en gaan we richting 1.0.

## Uitgangspunten

- **Puur op de Cirrux public API**, geen CalDAV.
- **De API niet overvragen.** Bezoekers van een boekingspagina lezen alleen uit de cache; de API wordt alleen geraakt door een scheduled job en op het moment van boeken.
- **Simpel houden.** Het package levert alleen methodes: API-client, caching, beschikbaarheid en boeken/annuleren. Geen routes, geen UI.

## Buiten scope

Bewust níet in dit package; dat bouwt de app die het gebruikt (bijv. Campmaster voor demo-aanvragen) er zelf overheen:

- boekingspagina, formulieren en extra vragen
- verzetten via links, annuleringstokens en bevestigingspagina's
- meeting-links, herinneringen, CRM- of Slack-koppelingen (via de Laravel-events)
- alles wat Cal.com groot maakt: teams, round-robin, routing, betalingen, workflows

## Wat we weten over de API

Agenda-endpoints zijn nog niet gedocumenteerd op cirrux.co/help/api. Onderstaande komt uit de broncode van de open source CLI ([cirrux-co/cli](https://github.com/cirrux-co/cli), stand 12 okt 2026).

Basis-URL: `https://api.cirrux.co/public_api/v1`

| Methode | Pad | Doel |
| --- | --- | --- |
| GET | `/calendars` | Agenda's van de gebruiker |
| GET | `/calendars/{uuid}/events?time_min&time_max&timezone&limit&cursor` | Events in een venster; herhalende events uitgeklapt; cursor-paginering (`has_more`, `next_cursor`) |
| POST | `/calendars/{uuid}/events` | Event aanmaken |
| POST | `/calendars/{uuid}/events/{id}` | Event wijzigen (event-UUID = hele serie, occurrence-id = één voorkomen) |
| DELETE | `/calendars/{uuid}/events/{id}` | Event verwijderen |

Event-body:

```json
{
  "title": "Demo Trivle",
  "description": "…",
  "location": "…",
  "url": "…",
  "transparency": "opaque",
  "recurrence": "…",
  "start": { "date_time": "2026-10-20T10:00", "time_zone": "Europe/Amsterdam" },
  "end":   { "date_time": "2026-10-20T10:30", "time_zone": "Europe/Amsterdam" },
  "attendees": [{ "email": "jan@example.com", "name": "Jan" }]
}
```

All-day events gebruiken `{ "date": "YYYY-MM-DD" }` in plaats van `date_time`.

Belangrijk: **attendees krijgen van Cirrux automatisch een uitnodiging per mail**, en een annulering als het event verwijderd wordt. Bevestigingsmails hoeven we dus niet zelf te bouwen.

Overig:
- Scopes: `calendar.read`, `calendar.write`.
- Rate limit: 600 requests/minuut per token. `429` met `Retry-After` en `X-RateLimit-*` headers.
- Geen free/busy-endpoint → beschikbaarheid zelf berekenen uit events, `transparency: transparent` negeren.
- Webhooks bestaan alleen voor binnenkomende mail, niet voor agenda-wijzigingen → pollen.

### Authenticatie

- Alleen **user-scoped OAuth-tokens** werken voor agenda's; een workspace API key krijgt `403 forbidden`.
- De CLI gebruikt OAuth + PKCE via:
  - `POST api/auth/get_authorization_url` (`client_id`, `redirect_uri`, `code_challenge`, `code_challenge_method: S256`)
  - `POST api/auth/token` (`code`, `code_verifier`)
  - **Device flow:** `POST api/auth/device_authorization` → gebruiker opent `verification_uri` en voert `user_code` in → pollen op `POST api/auth/device_token`
  - `POST api/auth/refresh_token` — refresh tokens **roteren** bij elke refresh
- Er is geen manier om een eigen OAuth-client te registreren; de CLI gebruikt de vaste `client_id: cirrux-cli`.

## Open punten (eerst oplossen)

1. **Eigen OAuth client-id.** Meeliften op `cirrux-cli` is verdedigbaar voor eigen gebruik, niet voor een publiek package. Rick Pastoor (Telegram/Signal) vragen om een eigen client-id of een officiële route voor third-party apps.
2. **Endpoint-stabiliteit.** Agenda-endpoints zijn ongedocumenteerd en kunnen wijzigen. Vragen of ze als stabiel beschouwd mogen worden; tot die tijd contract-tests op het gedrag van een gepinde CLI-versie.

## Publieke API van het package

Via een `Cirrux`-facade. Lezen gaat standaard uit de cache.

### Agenda's en events

```php
Cirrux::calendars(): Collection<Calendar>                       // cache 24 uur

Cirrux::calendar($uuid)->events()
    ->between(CarbonInterface $from, CarbonInterface $to)
    ->get(): Collection<Event>                                   // uit weekcache

Cirrux::calendar($uuid)->events()->fresh()->between(...)->get() // cache overslaan

Cirrux::calendar($uuid)->events()->create(NewEvent $event): Event
Cirrux::calendar($uuid)->events()->update(string $eventId, EventChanges $changes): Event
Cirrux::calendar($uuid)->events()->delete(string $eventId): void
```

### Beschikbaarheid

```php
Cirrux::availability('demo')                    // event type uit config
    ->between($from, $to)
    ->inTimezone('Europe/Amsterdam')
    ->slots(): Collection<Slot>

Cirrux::availability('demo')->isFree(CarbonInterface $start): bool   // verse check
```

### Boeken

```php
Cirrux::book('demo', CarbonInterface $start, Attendee $attendee, ?string $notes = null): Booking
// lock op slot → verse check van dat venster → event aanmaken met attendee → cache bijwerken
// gooit SlotUnavailableException als het slot bezet is

Cirrux::cancel(Booking|string $booking): void   // Cirrux mailt de annulering
```

### Beheer

```php
Cirrux::refresh(?string $calendarUuid = null): void   // aangeroepen door de scheduled job
Cirrux::forget(?string $calendarUuid = null): void    // cache legen
```

### DTO's

- `Calendar`: uuid, name, color
- `Event`: id, title, start, end, allDay, transparency, attendees, isRecurring
- `Slot`: start, end
- `Booking`: id, eventId, start, end, attendee, eventType
- `Attendee`: email, name
- `NewEvent`, `EventChanges`: invoer voor create/update

### Laravel-events

- `BookingCreated`
- `BookingCancelled`

Zodat de app er iets mee kan, zoals een lead aanmaken.

## Caching

1. **Events per agenda per ISO-week** in de cache, key `cirrux:events:{calendar}:{yyyy}-W{ww}`.
2. **Scheduled job** (`Cirrux::refresh()`, standaard elke 5 min) haalt de komende N weken (default 4) op in één call (plus paginering). ≈ 12 calls per uur, onafhankelijk van bezoekersaantallen.
3. **Lezen met `Cache::flexible()`** (stale-while-revalidate): bezoekers krijgen altijd direct antwoord, verversen gebeurt na de response.
4. **Write-through:** create/update/delete via het package werkt direct de betreffende weekcache bij.
5. **Verse check bij boeken:** de enige ongecachte read. `Cache::lock` op het slot, alleen dat tijdvenster ophalen, dan pas aanmaken. Voorkomt dubbele boekingen bij gelijktijdige klikken of handmatige wijzigingen in Cirrux.
6. **Agendalijst:** 24 uur.
7. **Access token:** gecached tot `access_token_expires_in` min marge; verversen alleen dan of bij een 401, **achter een lock** (roterende refresh tokens maken elkaar anders ongeldig).
8. **Client-side rate limiting** (Saloon rate-limit-plugin) ruim onder de limiet (default 60/min). Bij `429` wordt `Retry-After` gerespecteerd en blijft de cache dienen.

Consequentie: wijzigingen die je zelf in Cirrux maakt zijn pas na maximaal één poll-interval zichtbaar in de getoonde slots. De verse check bij boeken voorkomt dat dit tot een dubbele boeking leidt.

## Configuratie (`config/cirrux.php`)

```php
return [
    'base_url' => env('CIRRUX_BASE_URL', 'https://api.cirrux.co/'),
    'client_id' => env('CIRRUX_CLIENT_ID'),

    'token_store' => 'database',   // of 'cache'; refresh token altijd versleuteld

    'cache' => [
        'store' => env('CIRRUX_CACHE_STORE'),
        'fresh' => 300,            // seconden vers
        'stale' => 900,            // seconden stale-while-revalidate
        'weeks_ahead' => 4,
        'calendars_ttl' => 86400,
    ],

    'rate_limit' => [
        'per_minute' => 60,
    ],

    'event_types' => [
        'demo' => [
            'calendar' => env('CIRRUX_DEMO_CALENDAR'),
            'title' => 'Demo',
            'duration' => 30,                 // minuten
            'buffer_before' => 10,
            'buffer_after' => 10,
            'slot_interval' => 30,
            'min_notice' => 60 * 24,          // minuten
            'max_days_ahead' => 21,
            'timezone' => 'Europe/Amsterdam',
            // Eerste use case: demo's alleen op maandag en vrijdag.
            // Dagen die ontbreken zijn niet boekbaar.
            'hours' => [
                'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
                'fri' => [['09:00', '12:00'], ['13:00', '17:00']],
            ],
        ],
    ],
];
```

## Artisan-commands

- `cirrux:login` — device flow: toont URL + code, pollt, slaat tokens versleuteld op.
- `cirrux:logout` — tokens verwijderen.
- `cirrux:calendars` — agenda's met UUID tonen (om de config te vullen).
- `cirrux:refresh` — cache verversen (wat de scheduler draait).

## HTTP-laag: Saloon

De verbinding met Cirrux bouwen we met [Saloon](https://docs.saloon.dev) v4 (`saloonphp/saloon` ^4.5) en de officiële plugins:

| Package | Gebruik |
| --- | --- |
| `saloonphp/laravel-plugin` ^5 | Laravel-integratie, `MockClient`/`Saloon::fake()` in Pest-tests |
| `saloonphp/rate-limit-plugin` ^2.5 | `HasRateLimits` op de connector met `LaravelCacheStore`, `Limit::allow(60)->everyMinute()`; `429` → `Retry-After` respecteren |
| `saloonphp/pagination-plugin` ^2.3 | `CursorPaginator` voor de eventlijst (`has_more`, `next_cursor`) |
| `saloonphp/cache-plugin` ^3.3 | Alleen voor de agendalijst (24 uur) |

Events cachen we **niet** met de cache-plugin: die cachet per response, terwijl wij weekbuckets, `Cache::flexible()` en write-through nodig hebben. Daarvoor blijft `EventCache` een eigen class bovenop de connector.

**Twee connectors:**
- `CirruxAuthConnector` (basis `https://api.cirrux.co/`): de `api/auth/*`-requests. Dit is geen standaard OAuth2-server (JSON-bodies, eigen device flow), dus geen gebruik van Saloon's ingebouwde OAuth2-traits maar eigen requests.
- `CirruxConnector` (basis `https://api.cirrux.co/public_api/v1`): de API zelf. Authenticatie via `TokenAuthenticator` met het access token uit de `TokenStore`.

**Tokenbeheer:** proactief verversen als het access token binnen de marge verloopt, en éénmalig opnieuw proberen bij een `401` via `$tries = 2` en `handleRetry()` op de connector. Beide paden lopen via dezelfde `TokenRefresher` met een `Cache::lock`, zodat er nooit twee refreshes tegelijk lopen (roterende refresh tokens).

**Requests en DTO's:** elke request implementeert `createDtoFromResponse()`, zodat `$connector->send(new ListEvents(...))->dto()` direct getypeerde DTO's oplevert.

## Interne opbouw

```
src/
  CirruxCalendarServiceProvider.php
  Facades/Cirrux.php
  Cirrux.php                    // entry point achter de facade
  Http/
    CirruxConnector.php         // public_api/v1, TokenAuthenticator, HasRateLimits, handleRetry bij 401
    CirruxAuthConnector.php     // api/auth/*
    Requests/
      Auth/                     // GetAuthorizationUrl, ExchangeCode, DeviceAuthorization, DeviceToken, RefreshToken
      Calendars/                // ListCalendars
      Events/                   // ListEvents (paginated), CreateEvent, UpdateEvent, DeleteEvent
  Auth/DeviceFlow.php
  Auth/TokenRefresher.php       // lock + refresh + opslaan
  Auth/TokenStore.php           // contract + DatabaseTokenStore, CacheTokenStore
  Calendars/CalendarResource.php
  Calendars/EventQuery.php
  Calendars/EventCache.php      // weekbuckets, flexible, write-through
  Availability/Availability.php // slots berekenen uit werktijden minus bezette events
  Booking/Booker.php            // lock → verse check → create → cache bijwerken
  Data/                         // DTO's
  Events/                       // BookingCreated, BookingCancelled
  Exceptions/                   // SlotUnavailableException, CirruxAuthException, RateLimitedException
  Commands/
database/migrations/            // cirrux_tokens, cirrux_bookings
config/cirrux.php
```

`cirrux_bookings` houdt de koppeling bij tussen een boeking (eigen id) en het Cirrux-event-id, zodat de app die erbovenop bouwt nooit met Cirrux-ids hoeft te werken.

## Kwaliteit en tooling

- **Ondersteuning:** PHP 8.3, 8.4 en 8.5+ (`"php": "^8.3"`), Laravel 12 en 13. Testen via Orchestra Testbench.
- **Code style:** Laravel Pint met de `laravel`-preset. CI faalt op `pint --test`.
- **Tests:** Pest, inclusief architectuurtests:
  - `declare(strict_types=1)` in alle bestanden onder `src/`
  - geen `dd`, `dump`, `ray` of `env()` buiten `config/`
  - DTO's zijn `readonly`
  - exceptions erven van een gedeelde `CirruxException`
- **Type coverage:** `pest --type-coverage --min=100`. Elke parameter, property en return type expliciet getypeerd; generics via PHPDoc (`Collection<int, Event>`).
- **Statische analyse:** Larastan op level `max`, zonder baseline.
- **Coverage:** minimaal 90% code coverage (`pest --coverage --min=90`).
- **Composer-scripts:** `test`, `test:types`, `test:coverage`, `analyse`, `format`, plus `check` dat ze allemaal draait.
- **CI:** GitHub Actions met een matrix over PHP 8.3/8.4/8.5 × Laravel 12/13 × prefer-lowest/prefer-stable; Pint, Larastan en type coverage als aparte jobs.

## Fasering

1. **Fundament** — package-skelet (spatie/laravel-package-tools), Pint, Pest, Larastan, type coverage en CI vanaf de eerste commit, config, Saloon-connectors, token-opslag, `cirrux:login` (device flow), `cirrux:calendars`. Doel: met eigen account agenda's kunnen ophalen.
2. **Events** — `EventQuery`, DTO's, create/update/delete, paginering.
3. **Caching** — `EventCache`, `cirrux:refresh`, write-through, rate limiting en 429-afhandeling via de rate-limit-plugin.
4. **Beschikbaarheid** — slot-berekening incl. buffers, min notice, tijdzones, zomertijd-overgangen.
5. **Boeken** — `book()`/`cancel()`, lock + verse check, `cirrux_bookings`, Laravel-events.

## Testen

- Unit: slot-berekening (werktijden, buffers, overlappende events, transparante events, all-day events, DST-wissel eind oktober/eind maart).
- Feature: Saloon `MockClient` met fixtures per request (geen echte HTTP-calls); `MockClient::assertSent()`/`assertSentCount()` voor refresh-bij-401, token-rotatie onder gelijktijdigheid, 429 met `Retry-After`.
- Cache: aantal uitgaande requests asserten (pagina-views mogen 0 calls opleveren).
- Contract: kleine opt-in testsuite tegen een echt Cirrux-account om te detecteren wanneer de ongedocumenteerde API verandert.
