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
