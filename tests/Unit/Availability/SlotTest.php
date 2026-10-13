<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Slot;

it('presents a slot in another zone without moving its instants', function (): void {
    $slot = new Slot(CarbonImmutable::parse('2026-10-19T07:00:00Z'), CarbonImmutable::parse('2026-10-19T07:30:00Z'));
    $local = $slot->inTimezone('Europe/Amsterdam');

    expect($local->start->format('H:i'))->toBe('09:00')
        ->and($local->end->format('H:i'))->toBe('09:30')
        ->and($local->start->getTimestamp())->toBe($slot->start->getTimestamp())
        ->and($local->end->getTimestamp())->toBe($slot->end->getTimestamp())
        ->and($slot->start->format('H:i'))->toBe('07:00');
});

it('turns a slot into an array of ISO 8601 strings', function (): void {
    $slot = new Slot(CarbonImmutable::parse('2026-10-19T07:00:00Z'), CarbonImmutable::parse('2026-10-19T07:30:00Z'));

    expect($slot->toArray())->toBe(['start' => '2026-10-19T07:00:00+00:00', 'end' => '2026-10-19T07:30:00+00:00'])
        ->and($slot->inTimezone('Europe/Amsterdam')->toArray())->toBe(['start' => '2026-10-19T09:00:00+02:00', 'end' => '2026-10-19T09:30:00+02:00']);
});
