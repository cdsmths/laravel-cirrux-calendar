# laravel-cirrux-calendar

> **Experimenteel package.** De agenda-endpoints van Cirrux zijn (nog) niet publiek gedocumenteerd. Wat dit package gebruikt is afgeleid uit de broncode van de open source Cirrux CLI en kan zonder aankondiging veranderen. Het package is niet officieel en niet verbonden aan Cirrux.
>
> We blijven op **0.x-versies** zolang de endpoints ongedocumenteerd zijn: minor releases kunnen breaking changes bevatten.

Laravel-package om met de API van [Cirrux](https://cirrux.co) te praten: agenda's lezen (uit de cache), beschikbaarheid berekenen en afspraken boeken of annuleren. Geen routes, geen UI.

Status: in ontwikkeling (fase 1: fundament). Zie [plan.md](plan.md) voor het ontwerp.

## Installatie

```bash
composer require cdsmths/laravel-cirrux-calendar
```

Publiceer de migratie (tabel `cirrux_tokens`, waarin de tokens versleuteld staan) en voer hem uit. De config is optioneel te publiceren.

```bash
php artisan vendor:publish --tag=cirrux-migrations
php artisan migrate

php artisan vendor:publish --tag=cirrux-config   # optioneel
```

## Gebruik

### Commands

```bash
php artisan cirrux:login       # device flow: toont een URL en code, wacht op goedkeuring
php artisan cirrux:logout      # verwijdert de opgeslagen tokens
php artisan cirrux:calendars   # tabel met naam, UUID en kleur van je agenda's
```

`cirrux:calendars` vraagt Cirrux altijd opnieuw (en ververst daarmee de cache), zodat je de UUID's voor de config kunt overnemen. Ben je niet ingelogd, dan wijst het command je naar `cirrux:login`.

### Agenda's ophalen

```php
use Codesmiths\CirruxCalendar\Facades\Cirrux;

Cirrux::calendars(); // Collection<int, Calendar>: uuid, name, color
```

De lijst komt uit de cache (`cirrux.cache.calendars_ttl`, standaard 24 uur) op de store uit `cirrux.cache.store`.

## Ontwikkeling

```bash
composer test:lint
composer test:types
composer test:type-coverage
composer test:unit
composer test:contract   # opt-in, vereist CIRRUX_* credentials
```

## Licentie

MIT
