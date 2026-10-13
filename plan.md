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
- **De API niet overvragen.** Bezoekers van een boekingspagina lezen alleen uit de cache; de API wordt alleen geraakt door een scheduled job en op het moment van boeken. Geen enkel bezoekerspad mag een API-call veroorzaken, ook niet indirect.
- **Fail closed.** Geen of te oude data betekent: geen slots tonen. Liever tijdelijk niets boekbaar dan een dubbele boeking of een slot dat bij het klikken bezet blijkt.
- **Simpel houden.** Het package levert alleen methodes: API-client, caching, beschikbaarheid en boeken/annuleren. Geen routes, geen UI. Alleen bouwen wat de demo-use-case nodig heeft; elk extra stuk moeten we bij elke API-wijziging meeslepen.

## Buiten scope

Bewust níet in dit package; dat bouwt de app die het gebruikt (bijv. Campmaster voor demo-aanvragen) er zelf overheen:

- boekingspagina, formulieren en extra vragen
- verzetten via links, annuleringstokens en bevestigingspagina's
- meeting-links, herinneringen, CRM- of Slack-koppelingen (via de Laravel-events)
- alles wat Cal.com groot maakt: teams, round-robin, routing, betalingen, workflows

**Later, bij concrete vraag** (niet in fase 1–5):

- `update()` / `EventChanges` — geen use case heeft het nu nodig.
- OAuth + PKCE-browserflow (`get_authorization_url`, `token`) — zonder routes heeft het package daar niets aan; de device flow volstaat.

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

## Open punten

1. **Eigen OAuth client-id.** Meeliften op `cirrux-cli` is verdedigbaar voor eigen gebruik, niet voor een publiek package. Rick Pastoor (Telegram/Signal) vragen om een eigen client-id of een officiële route voor third-party apps. **Blokkeert fase 1 niet:** we bouwen met `CIRRUX_CLIENT_ID` (default `cirrux-cli` voor eigen gebruik), maar publiceren pas na antwoord.
2. **Endpoint-stabiliteit.** Agenda-endpoints zijn ongedocumenteerd en kunnen wijzigen. Vragen of ze als stabiel beschouwd mogen worden; tot die tijd contract-tests op het gedrag van een gepinde CLI-versie.
3. **Semantiek van de lijst-endpoint.** Onbekend en vast te leggen in de contract-tests vóór fase 3:
   - Geeft `time_min`/`time_max` events terug die het venster *overlappen*, of alleen events die erbinnen *starten*?
   - Wat doet de `timezone`-parameter met de output?
   - Welke id krijgt een uitgeklapt voorkomen van een serie, en zit het serie-UUID ook in het antwoord?

## Publieke API van het package

Via een `Cirrux`-facade. Lezen gaat altijd uit de cache.

### Agenda's en events

```php
Cirrux::calendars(): Collection<int, Calendar>                  // cache 24 uur

Cirrux::calendar($uuid)->events()
    ->between(CarbonInterface $from, CarbonInterface $to)
    ->get(): Collection<int, CalendarEvent>                              // uit weekcache

Cirrux::calendar($uuid)->events()->fresh()->between(...)->get() // cache overslaan (niet voor bezoekerspaden)

Cirrux::calendar($uuid)->events()->create(NewEvent $event): Event
Cirrux::calendar($uuid)->events()->delete(string $eventId): void
```

### Beschikbaarheid

```php
Cirrux::availability('demo')                    // event type uit config
    ->between($from, $to)
    ->inTimezone('Europe/Amsterdam')            // alleen presentatie
    ->slots(): Collection<int, Slot>

Cirrux::availability('demo')->isFree(CarbonInterface $start): bool
// Verse check tegen de API. Niet aanroepen vanuit bezoekerspagina's; bedoeld voor
// de app vlak vóór het boeken of voor beheer. book() doet dezelfde check zelf al.
```

Slotberekening is een **pure functie** (`SlotCalculator`) over werktijden, regels en een lijst events, zonder cache of HTTP. `slots()`, `isFree()` en `book()` gebruiken allemaal diezelfde functie.

**Buffers** gelden rond álle bezette (opaque) events in de agenda, niet alleen rond eigen boekingen: een slot is vrij als `[start − buffer_before, end + buffer_after]` niet overlapt met een opaque event.

Consequentie bij de demo-config (duur 30, buffers 10/10, interval 30): na een boeking om 10:00 is 11:00 het eerstvolgende vrije slot, want 10:30 botst met de buffers. Wil je wel elk half uur een demo kunnen geven, zet dan de buffers op 0 of het interval op 40.

### Boeken

```php
Cirrux::book('demo', CarbonInterface $start, Attendee $attendee, ?string $notes = null): Booking
Cirrux::cancel(Booking|string $booking): void   // Cirrux mailt de annulering
```

Stappen van `book()`:

1. `$start` toetsen aan de regels van het event type: slotraster (`slot_interval`), `hours`, `min_notice`, `max_days_ahead`, niet in het verleden. Klopt dat niet → `InvalidSlotException`.
2. **Lock per agenda** (`cirrux:lock:{calendar}`), niet per slot: aangrenzende slots overlappen door de buffers. Lukt de lock niet binnen de timeout → `SlotUnavailableException`. Bij elke afwijzing volgt `BookingRejected`. De refresh-job neemt dezelfde lock.
3. Verse check: events ophalen met een ruim venster (hele dag rond het slot, plus de buffers), daarna lokaal filteren op echte overlap. Zo missen we niets als de API alleen events teruggeeft die binnen het venster starten. Bezet → `SlotUnavailableException`.
4. `BookingCreating` dispatchen; een listener kan hier nog een veto geven.
5. Event aanmaken met de attendee (geen `recurrence`, request met `$tries = 1`).
6. Rij in `cirrux_bookings` aanmaken. Mislukt dat, dan verwijderen we het event weer (compenserend), zodat er geen boeking in de agenda achterblijft die de app niet kent.
7. Write-through in de weekcache, lock vrijgeven, `BookingCreated` dispatchen.

`cancel()` is idempotent: een `404` bij het verwijderen geldt als succes. Daarna wordt de status `cancelled` (`cancelled_by: user`) en volgt `BookingCancelled`.

### Beheer

```php
Cirrux::refresh(?string $calendarUuid = null): void   // aangeroepen door de scheduled job
Cirrux::forget(?string $calendarUuid = null): void    // cache legen
```

### DTO's

Alle tijden worden in `createDtoFromResponse` meteen genormaliseerd naar `CarbonImmutable`-instants in UTC. Andere tijdzones bestaan alleen aan de rand (invoer en presentatie).

Een agenda-item heet overal `CalendarEvent`, zodat het niet verward wordt met de Laravel-events hieronder.

- `Calendar`: uuid, name, color
- `CalendarEvent`: id (occurrence), seriesId (nullable), title, start, end, allDay, transparency, attendees, isRecurring
- `Slot`: start, end
- `Booking`: id, eventId, start, end, attendee, eventType, status
- `Attendee`: email, name
- `NewEvent`: invoer voor create

### Laravel-events

Het package dispatcht bij elke betekenisvolle stap een eigen Laravel-event, zodat apps en andere packages erop kunnen voortbouwen zonder het package aan te passen: een lead aanmaken in het CRM, een Slack-bericht sturen, een meeting-link toevoegen, metrics bijhouden of een alert geven als de koppeling stuk is.

**Boekingen**

| Event | Wanneer | Payload |
| --- | --- | --- |
| `BookingCreating` | in `BookSlot`, na validatie en verse check, vóór het aanmaken in Cirrux | `eventType`, `Slot`, `Attendee`, `notes` |
| `BookingCreated` | boeking staat in Cirrux en in `cirrux_bookings` | `Booking` |
| `BookingRejected` | slot ongeldig of bezet (`InvalidSlotException`/`SlotUnavailableException`) | `eventType`, `start`, `Attendee`, `reason` |
| `BookingCancelled` | geannuleerd via het package of extern verwijderd | `Booking`, `cancelledBy` (`user`/`external`) |
| `BookingRescheduled` | reconciliatie ziet dat het event in Cirrux is verschoven | `Booking`, `previousStart`, `previousEnd` |

`BookingCreating` is het enige "vóór"-event. Een listener mag daar een `BookingVetoedException` (subclass van `CirruxException`) gooien om de boeking tegen te houden, bijvoorbeeld bij een geblokkeerd e-maildomein. `BookSlot` geeft daarna de lock vrij en dispatcht `BookingRejected`. Wie meer wil aanpassen, vervangt de Action via de container.

**Agenda-items** (alleen wat via het package loopt; wijzigingen die iemand direct in Cirrux maakt, zien we alleen bij de refresh)

| Event | Wanneer | Payload |
| --- | --- | --- |
| `CalendarEventCreated` | `CreateEvent` geslaagd | `calendarUuid`, `CalendarEvent` |
| `CalendarEventDeleted` | `DeleteEvent` geslaagd (ook bij `404`) | `calendarUuid`, `eventId` |

**Cache en synchronisatie**

| Event | Wanneer | Payload |
| --- | --- | --- |
| `CalendarRefreshed` | `RefreshCalendar` geslaagd | `calendarUuid`, `from`, `to`, aantal events, aantal API-calls |
| `CalendarRefreshFailed` | refresh mislukt; de oude cache blijft staan | `calendarUuid`, `CirruxFailure` |
| `CalendarStale` | `refreshed_at` ouder dan `max_staleness`; er worden geen slots getoond | `calendarUuid`, `refreshedAt` |
| `RateLimited` | `429` van Cirrux of de lokale limiter | `retryAfter` |

`CalendarStale` wordt hooguit één keer per `max_staleness`-periode per agenda gedispatcht (cache-flag), zodat een drukke boekingspagina geen alert-storm geeft.

**Authenticatie**

| Event | Wanneer | Payload |
| --- | --- | --- |
| `LoggedIn` | `CompleteDeviceLogin` geslaagd | `expiresAt` |
| `LoggedOut` | `Logout` | — |
| `TokenRefreshed` | `RefreshAccessToken` geslaagd | `expiresAt` |
| `TokenRefreshFailed` | refresh definitief mislukt; iemand moet `cirrux:login` draaien | `CirruxFailure` |

Tokens zitten nooit in een payload.

Conventies:

- Alle events staan in `src/Events/`, zijn `final readonly` en gebruiken `Dispatchable`.
- De payload bestaat uit DTO's en scalars, geen Eloquent-models, connectors of exceptions. Zo zijn ze veilig te serialiseren voor queued listeners. Een mislukking gaat mee als `CirruxFailure` (exception-class, message, status, `retryAfter`): een gegooide exception heeft closures in de stacktrace en is niet te serialiseren.
- Alleen Actions dispatchen events. Facade, commands en de HTTP-laag doen dat nooit zelf.
- Events die volgen op een database-write (`BookingCreated`, `BookingCancelled`, `BookingRescheduled`) implementeren `ShouldDispatchAfterCommit`. Listeners zien dus nooit een boeking die daarna wordt teruggedraaid.
- De events zijn publieke API: de payload hernoemen of weghalen is een breaking change en komt in de changelog. De README krijgt een tabel met alle events.

## Caching

1. **Events per agenda per ISO-week (UTC)** in de cache, key `cirrux:events:{calendar}:{yyyy}-W{ww}`. Een event komt in elke week die het overlapt, dus ook meerdaagse events en events over een weekgrens. Lezen pakt alle buckets die het venster raken, ontdubbelt op id en filtert op overlap.
2. **Scheduled job** (`Cirrux::refresh()`, standaard elke 5 min) haalt de komende N weken (default 4) op in één call (plus paginering). ≈ 12 calls per uur, onafhankelijk van bezoekersaantallen.
   - Neemt de agenda-lock, zodat refresh en boeken elkaar niet overschrijven.
   - Haalt eerst **alle pagina's** op en schrijft pas daarna alle buckets in één `putMany`. Mislukt het halverwege (429/5xx), dan blijft de oude cache onaangeroerd.
   - Schrijft in dezelfde `putMany` de metadata `cirrux:refreshed:{calendar}`: `refreshed_at` plus het gedekte venster (`from`, `to`). Lezen geeft alleen data binnen dat venster; zo telt een achtergebleven bucket van een oudere refresh niet mee.
3. **Gewone `Cache::put`/`get` met een lange TTL** (default 24 uur, "last known good"); geen `Cache::flexible()`. Bij flexible zou een bezoekersrequest de refresh triggeren, en het eigen opslagformaat van flexible botst met write-through.
4. **Fail closed:** ontbreekt een bucket, valt het venster buiten het laatst gerefreshte venster, of is `refreshed_at` ouder dan `max_staleness` (default 2 uur), dan levert `slots()` niets op en dispatcht het package `CalendarStale`. "Geen data" is iets anders dan "geen events".
5. **Write-through:** create en delete via het package werken het bestaande bucket bij door één event toe te voegen of te verwijderen (merge), niet door het bucket te vervangen. Raakt een wijziging een herhalende serie, dan worden alle buckets van die agenda vergeten en volgt direct `refresh($calendar)`.
6. **Verse check bij boeken:** de enige ongecachte read, zie [Boeken](#boeken).
7. **Agendalijst:** 24 uur, via `Cache::remember`.
8. **Rate limiting:** de rate-limit-plugin (default 60/min, ruim onder de limiet). Bij `429` wordt `Retry-After` gerespecteerd; de job vangt de exception en de cache blijft dienen.

Consequentie: wijzigingen die je zelf in Cirrux maakt zijn pas na maximaal één poll-interval zichtbaar in de getoonde slots. De verse check bij boeken voorkomt dat dit tot een dubbele boeking leidt.

**Locks** vereisen een cache-store die locks ondersteunt en gedeeld wordt tussen web-workers en de scheduler (redis, database, memcached, dynamodb). Bij `array`/`null` zou de lock niets doen; de service provider weigert die stores buiten tests.

## Tokenbeheer

- Opslag alleen in de **database** (`cirrux_tokens`), refresh token versleuteld. Geen cache-store: met roterende refresh tokens betekent `cache:clear` of eviction dat je permanent bent uitgelogd.
- Access token proactief verversen als het binnen de marge verloopt, en eenmalig bij een `401`.
- **Double-checked refresh:** lock nemen, dan de store opnieuw lezen. Is het access token inmiddels geldig (een ander proces heeft ververst), dan niet nogmaals verversen. Anders zou het tweede proces refreshen met een al ingetrokken refresh token.
- Nieuw tokenpaar **direct en atomair** opslaan, vóórdat het gebruikt wordt.
- Mislukt de refresh definitief, dan volgen `CirruxAuthException` en `TokenRefreshFailed`.

## Configuratie (`config/cirrux.php`)

```php
return [
    'base_url' => env('CIRRUX_BASE_URL', 'https://api.cirrux.co/'),
    'client_id' => env('CIRRUX_CLIENT_ID', 'cirrux-cli'),

    'cache' => [
        'store' => env('CIRRUX_CACHE_STORE'),   // moet locks ondersteunen en gedeeld zijn
        'ttl' => 86400,                          // last known good
        'max_staleness' => 7200,                 // daarboven: geen slots
        'weeks_ahead' => 4,
        'calendars_ttl' => 86400,
    ],

    'lock' => [
        'timeout' => 10,                         // seconden wachten op de agenda-lock
    ],

    'rate_limit' => [
        'per_minute' => 60,
    ],

    'event_types' => [
        'demo' => [
            'calendar' => env('CIRRUX_DEMO_CALENDAR'),
            'title' => 'Demo',
            'duration' => 30,                 // minuten
            'buffer_before' => 10,            // rond alle opaque events
            'buffer_after' => 10,
            'slot_interval' => 30,
            'min_notice' => 60 * 24,          // minuten
            'max_days_ahead' => 21,           // moet <= weeks_ahead * 7
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

De service provider valideert de config bij het booten: `max_days_ahead <= weeks_ahead * 7`, geldige tijdzone en werktijden, en een lock-capable cache-store.

## Tijdzones

- Intern rekenen we alleen met UTC-instants.
- De lijst-endpoint vragen we altijd op met de `timezone` van het event type. Wat die parameter precies doet, leggen we vast in de contract-tests.
- Slots worden gegenereerd in **lokale wandkloktijd** (`hours` in de tijdzone van het event type) en pas daarna omgezet naar instants. 30 minuten optellen in UTC zou op de dag van de zomertijdwissel een verschoven raster geven.
- `min_notice` en `max_days_ahead` worden in instants berekend; `inTimezone()` is alleen presentatie.

## Artisan-commands

- `cirrux:login` — device flow: toont URL + code, pollt, slaat tokens versleuteld op.
- `cirrux:logout` — tokens verwijderen.
- `cirrux:calendars` — agenda's met UUID tonen (om de config te vullen).
- `cirrux:refresh` — cache verversen en boekingen reconciliëren (wat de scheduler draait).

## HTTP-laag: Saloon

De verbinding met Cirrux bouwen we met [Saloon](https://docs.saloon.dev) v4 (`saloonphp/saloon` ^4.5) en de officiële plugins:

| Package | Gebruik |
| --- | --- |
| `saloonphp/laravel-plugin` ^5 | Laravel-integratie, `MockClient`/`Saloon::fake()` in Pest-tests. Vereist Laravel 13 |
| `saloonphp/rate-limit-plugin` ^2.5 | `HasRateLimits` op de connector met `LaravelCacheStore`, `Limit::allow(60)->everyMinute()`; `429` → `Retry-After` respecteren |
| `saloonphp/pagination-plugin` ^2.3 | `CursorPaginator` voor de eventlijst (`has_more`, `next_cursor`) |

Geen `saloonphp/cache-plugin`: voor de agendalijst volstaat `Cache::remember`, en events cachen we met een eigen `EventCache` (weekbuckets, write-through, fail closed).

Saloon v4 is pas kort uit; als plugins of docs achterlopen, is `^3.15` het terugvalpunt.

**Twee connectors:**
- `CirruxAuthConnector` (basis `https://api.cirrux.co/`): de `api/auth/*`-requests. Dit is geen standaard OAuth2-server (JSON-bodies, eigen device flow), dus geen gebruik van Saloon's ingebouwde OAuth2-traits maar eigen requests.
- `CirruxConnector` (basis `https://api.cirrux.co/public_api/v1`): de API zelf. `defaultAuth()` leest het access token **bij elke poging opnieuw** uit de `TokenStore` (niet in een property cachen), zodat een retry na een refresh het nieuwe token gebruikt.

**Retries:** `$tries = 2` op de connector, maar `handleRetry()` geeft alleen `true` terug bij een `401`. Dan ververst hij het token via de Action `RefreshAccessToken`; bij alle andere statussen geeft hij `false`. Write-requests (create/delete) hebben `$tries = 1`: een POST die wel aankwam maar met een 5xx antwoordde, mag geen dubbel event opleveren.

**Exceptions naar buiten:** `RateLimitedException`, `CirruxAuthException`, `CirruxRequestException`, `SlotUnavailableException`, `InvalidSlotException`. Ze erven allemaal van `CirruxException`.

**Requests en DTO's:** elke request implementeert `createDtoFromResponse()`, zodat `$connector->send(new ListEvents(...))->dto()` direct getypeerde DTO's oplevert.

## Interne opbouw

```
src/
  CirruxCalendarServiceProvider.php
  Facades/Cirrux.php
  Cirrux.php                    // entry point achter de facade
  Http/
    CirruxConnector.php         // public_api/v1, TokenAuthenticator, HasRateLimits, handleRetry alleen bij 401
    CirruxAuthConnector.php     // api/auth/*
    Requests/
      Auth/                     // DeviceAuthorization, DeviceToken, RefreshToken
      Calendars/                // ListCalendars
      Events/                   // ListEvents (paginated), CreateEvent, DeleteEvent
  Actions/                      // alle handelingen met bijwerkingen, zie "Actions"
    Auth/                       // StartDeviceLogin, CompleteDeviceLogin, RefreshAccessToken, Logout
    Events/                     // CreateEvent, DeleteEvent
    Cache/                      // RefreshCalendar, ForgetCalendar
    Booking/                    // BookSlot, CancelBooking, ReconcileBookings
  Auth/TokenStore.php           // contract + DatabaseTokenStore
  Calendars/CalendarResource.php
  Calendars/EventQuery.php      // lezen (uit cache of fresh)
  Calendars/EventCache.php      // weekbuckets, refreshed_at, merge, fail closed
  Availability/Availability.php // fluent API bovenop de calculator
  Availability/SlotCalculator.php // puur: werktijden + regels + events → slots
  Data/                         // DTO's
  Events/                       // Laravel-events, zie "Laravel-events"
  Exceptions/                   // CirruxException + subclasses
  Commands/
database/migrations/            // cirrux_tokens, cirrux_bookings
config/cirrux.php
```

`BookSlot`, `RefreshCalendar` en `RefreshAccessToken` krijgen de lock via Laravel's `LockProvider`-contract geïnjecteerd en de tokens via het `TokenStore`-contract. Zo kunnen tests races simuleren.

### Actions

Elke handeling met bijwerkingen is een eigen Action-class onder `src/Actions/`, zonder extra dependency (geen `lorisleiva/laravel-actions`). De facade, de commands en de connector doen zelf niets; ze roepen alleen Actions aan.

| Action | Doet | Aangeroepen door |
| --- | --- | --- |
| `Auth\StartDeviceLogin` | `device_authorization` → `DeviceCode` (url, user code, interval) | `cirrux:login` |
| `Auth\CompleteDeviceLogin` | pollt `device_token` tot akkoord, slaat tokens op | `cirrux:login` |
| `Auth\RefreshAccessToken` | lock → store herlezen → refresh → atomair opslaan | connector (proactief en bij `401`) |
| `Auth\Logout` | tokens verwijderen | `cirrux:logout` |
| `Events\CreateEvent` | event aanmaken + write-through | `CalendarResource`, `BookSlot` |
| `Events\DeleteEvent` | event verwijderen (404 = ok) + write-through | `CalendarResource`, `CancelBooking` |
| `Cache\RefreshCalendar` | lock → alle pagina's ophalen → `putMany` → `refreshed_at` | `Cirrux::refresh()`, `cirrux:refresh` |
| `Cache\ForgetCalendar` | buckets en `refreshed_at` wissen | `Cirrux::forget()` |
| `Booking\BookSlot` | valideren → lock → verse check → `BookingCreating` → `CreateEvent` → `cirrux_bookings` → `BookingCreated` | `Cirrux::book()` |
| `Booking\CancelBooking` | `DeleteEvent` → status `cancelled` → `BookingCancelled` | `Cirrux::cancel()` |
| `Booking\ReconcileBookings` | extern verwijderde of verschoven boekingen bijwerken | `RefreshCalendar` |

Conventies:

- `final readonly class`, afhankelijkheden via de constructor, één publieke methode `handle()` met getypeerde parameters en return type.
- Lezen (`EventQuery`, `Availability`, `SlotCalculator`) zijn géén Actions: die hebben geen bijwerkingen en blijven fluent.
- Actions worden uit de container geresolved. Een app kan er dus één vervangen met `$this->app->bind(BookSlot::class, …)`, bijvoorbeeld om extra validatie toe te voegen, zonder het package te forken.
- Actions roepen elkaar aan via constructor-injectie (`BookSlot` gebruikt `CreateEvent`), niet via de facade.

`cirrux_bookings` houdt de koppeling bij tussen een boeking (eigen id) en het Cirrux-event-id, zodat de app die erbovenop bouwt nooit met Cirrux-ids hoeft te werken. Kolommen o.a. `status` (`confirmed`/`cancelled`), `cancelled_at` en `cancelled_by` (`user`/`external`).

**Reconciliatie:** de refresh-job controleert de bevestigde boekingen binnen het opgehaalde venster. Ontbreekt het event in Cirrux (handmatig verwijderd), dan wordt de status `cancelled` met `cancelled_by: external` en volgt `BookingCancelled`. Een event dat in Cirrux is verschoven, krijgt in `cirrux_bookings` de nieuwe start en eind, en volgt `BookingRescheduled`.

## Kwaliteit en tooling

We volgen de code-patronen en tooling van Nuno Maduro, zoals in [`nunomaduro/laravel-starter-kit`](https://github.com/nunomaduro/laravel-starter-kit) (stand okt 2026). Waar een package iets anders vraagt dan een app, staat dat erbij.

### Codepatronen

- `declare(strict_types=1)` overal, en elke class `final`. Uitbreiden gebeurt via de container (Action vervangen) en via Laravel-events, niet via overerving.
- Actions: `final readonly class` met één `handle()`-methode (zie [Actions](#actions)).
- DTO's en events: `final readonly class` met promoted constructor-properties; geen arrays als publieke return type.
- Altijd `CarbonImmutable`, nooit mutable `Carbon` of `DateTime`.
- Strikte vergelijkingen (`===`), early returns, geen `else` na een `return`.
- Volledig getypeerd; docblocks alleen voor generics (`Collection<int, CalendarEvent>`) of als een type niet anders uit te drukken is.
- Beschrijvende namen (`isWithinWorkingHours()`, niet `check()`).
- Geen "Service"- of "Manager"-classes: handelingen zijn Actions, lezen gebeurt via query-objecten (`EventQuery`, `Availability`).
- Wat níet meekomt: `nunomaduro/essentials` vereisen we niet, omdat het globaal app-gedrag aanpast (strict models, immutable dates, enz.). Dat is aan de app, niet aan een package.

### Tooling

| Tool | Configuratie |
| --- | --- |
| **Pint** | `laravel`-preset plus Nuno's strikte regels: `declare_strict_types`, `final_class`, `date_time_immutable`, `strict_comparison`, `global_namespace_import`, `ordered_class_elements`, `protected_to_private`, `no_useless_else`, `no_superfluous_elseif`, enz. Níet `mb_str_functions`: op PHP 8.4+ maakt die van `rtrim` `mb_rtrim`, dat op 8.3 niet bestaat. |
| **Rector** | `rector/rector` + `driftingly/rector-laravel` met de Laravel-sets (code quality, collection, facade aliases, if-helpers), `withPreparedSets(deadCode, codeQuality, typeDeclarations, privatization, earlyReturn, codingStyle)` en `withPhpSets()`, die het PHP-niveau uit `composer.json` (8.3) haalt. |
| **Larastan** | level `max`, `bleedingEdge`, de Carbon-extensie, geen baseline. |
| **Pest** | v5, met `pest-plugin-laravel` en `pest-plugin-type-coverage`. |
| **Type coverage** | `--min=100`. |
| **Code coverage** | `--exactly=100.0`. De opt-in contract-suite telt niet mee. |
| **Overig** | `roave/security-advisories` als dev-dependency. Caches van Rector en PHPStan in `tmp/` (in `.gitignore`). |

**Architectuurtests** (`tests/Unit/ArchTest.php`):

- `arch()->preset()->php()`, `->strict()`, `->laravel()` en `->security()`.
- Uitzondering op `strict()`: de Saloon-connectors en -requests in `Http` mogen `protected` methodes hebben, omdat Saloon ze zo definieert (`defaultHeaders()`, `resolveEndpoint()`, `resolveLimits()`, …).
- Eigen regels bovenop de presets:
  - DTO's zijn `readonly`.
  - Exceptions erven van `CirruxException`.
  - Classes in `Actions` zijn `readonly` en hebben een publieke `handle()`-methode.
  - Classes in `Events` zijn `readonly`; alleen `Actions` mag events dispatchen.
  - `Facades`, `Commands` en `Http` doen geen schrijfacties zelf (geen `Cache`, `DB` of connectors), maar roepen Actions aan.
  - Geen `env()` buiten `config/`.

**`tests/Pest.php`**, zoals in de starter kit:

- `Http::preventStrayRequests()` en Saloon's `Config::preventStrayRequests()`: geen enkele test praat per ongeluk met Cirrux.
- `Sleep::fake()`: polling van de device flow en `Retry-After` kosten geen echte tijd. Daarom wacht de code altijd via `Sleep::for()`, nooit via `sleep()`.
- `$this->freezeTime()`: deterministische slots en tokenverlooptijden.
- `Str::createUuidsNormally()`.

**Composer-scripts** (namen als in de starter kit):

```json
"lint": ["rector", "pint --parallel"],
"test:lint": ["pint --parallel --test", "rector --dry-run"],
"test:types": "phpstan",
"test:type-coverage": "pest --type-coverage --min=100",
"test:unit": "XDEBUG_MODE=coverage pest --parallel --coverage --exactly=100.0",
"test:contract": "pest --testsuite=Contract",
"test": ["@test:lint", "@test:types", "@test:type-coverage", "@test:unit"]
```

### Ondersteuning en CI

- **Ondersteuning:** PHP 8.3, 8.4 en 8.5+ (`"php": "^8.3"`) en alleen Laravel 13 (`^13.0`). Testen via Orchestra Testbench 11. De starter kit vraagt PHP 8.5, maar voor een package houden we het bereik breed.
- **CI (GitHub Actions):**
  - `test:lint`, `test:types` en `test:type-coverage` als aparte jobs op PHP 8.5.
  - `test:unit` als matrix over PHP 8.3/8.4/8.5 met prefer-stable, plus prefer-lowest alleen op PHP 8.3.
  - Een wekelijkse workflow (`schedule`) die `test:contract` draait tegen de test-agenda, met secrets; mislukt die, dan is de API veranderd.

## Fasering

1. **Fundament** — package-skelet (spatie/laravel-package-tools), Pint, Rector, Pest, Larastan, type coverage, 100% coverage en CI vanaf de eerste commit, config + validatie, Saloon-connectors, `DatabaseTokenStore`, de auth-Actions, `cirrux:login` (device flow), `cirrux:calendars`. Doel: met eigen account agenda's kunnen ophalen.
2. **Events** — `EventQuery`, DTO's (UTC-normalisatie, `seriesId`), `CreateEvent`/`DeleteEvent`, paginering, contract-tests die open punt 3 beantwoorden.
3. **Caching** — `EventCache`, `RefreshCalendar`/`ForgetCalendar`, `cirrux:refresh`, write-through, fail closed, rate limiting en 429-afhandeling via de rate-limit-plugin.
4. **Beschikbaarheid** — `SlotCalculator` incl. buffers, min notice, tijdzones, zomertijd-overgangen.
5. **Boeken** — `BookSlot`/`CancelBooking`/`ReconcileBookings`, validatie, agenda-lock + verse check, `cirrux_bookings`, reconciliatie, boekings-events.

Elke fase levert de Laravel-events van de eigen Actions mee; ze zijn geen losse fase.

## Testen

- Unit: `SlotCalculator` als pure functie met `Carbon::setTestNow` — werktijden, buffers, overlappende events, transparante events, all-day events, events die vóór het venster beginnen, DST-wissel (25 okt 2026, 29 mrt 2026, en een slot precies op de overgang).
- Unit: `BookSlot`-validatie (buiten raster, buiten werktijden, binnen min notice, voorbij max days, verleden).
- Feature: Saloon `MockClient` met fixtures per request (geen echte HTTP-calls); `assertSent()`/`assertSentCount()` voor:
  - refresh bij een `401`, en geen retry bij andere statussen
  - double-checked refresh: een store die tussen het nemen van de lock en het verversen al ververst is, leidt niet tot een tweede refresh
  - `429` met `Retry-After`
  - paginering die halverwege faalt laat de cache ongemoeid
  - een lock-timeout geeft `SlotUnavailableException`
  - een mislukte insert leidt tot een compenserende delete
  - `cancel()` op een `404`
  - reconciliatie
- Cache: `assertSentCount(0)` per bezoekersscenario (slots tonen) en `assertSentCount(1)` voor de verse check van `book()`, ongeacht het aantal bezoekers. Ontbrekende of verouderde buckets geven geen slots.
- Events: met `Event::fake()` per Action asserten welke events met welke payload worden gedispatcht, en welke niet (bijv. geen `BookingCreated` na een veto of een mislukte insert). Een test dat `BookingVetoedException` in een listener de boeking tegenhoudt zonder API-call voor het aanmaken. Een test dat de payloads te serialiseren zijn (`serialize`/`unserialize` round-trip) en geen tokens bevatten.
- Contract: kleine opt-in testsuite tegen een vaste test-agenda (via env) om te detecteren wanneer de ongedocumenteerde API verandert. Legt overlap-semantiek, `timezone`-parameter en serie-ids vast. Ruimt op in `afterEach` en wordt overgeslagen (niet gefaald) zonder credentials.
