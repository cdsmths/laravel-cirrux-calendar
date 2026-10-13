<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Codesmiths\CirruxCalendar\Data\Transparency;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiEvent(array $overrides = []): array
{
    return [
        'object' => 'calendar_event',
        'id' => 'evt-1',
        'event_uuid' => 'evt-1',
        'series_uuid' => null,
        'title' => 'Review',
        'transparency' => 'opaque',
        'all_day' => false,
        'start' => ['date_time' => '2026-09-02T07:00:00Z', 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => '2026-09-02T08:00:00Z', 'time_zone' => 'Europe/Amsterdam'],
        'recurrence' => null,
        'attendees' => [],
        ...$overrides,
    ];
}

it('keeps an instant with Z as that instant, whatever the time zone label says', function (): void {
    $event = CalendarEvent::fromApi(apiEvent());

    expect($event->start->toIso8601ZuluString())->toBe('2026-09-02T07:00:00Z')
        ->and($event->end->toIso8601ZuluString())->toBe('2026-09-02T08:00:00Z')
        ->and($event->start->getTimezone()->getName())->toBe('UTC')
        ->and($event->allDay)->toBeFalse()
        ->and($event->isRecurring)->toBeFalse()
        ->and($event->seriesId)->toBeNull();
});

it('keeps an instant with an offset as that instant', function (): void {
    $event = CalendarEvent::fromApi(apiEvent([
        'start' => ['date_time' => '2026-09-02T09:00:00+02:00', 'time_zone' => 'Europe/Amsterdam'],
    ]));

    expect($event->start->toIso8601ZuluString())->toBe('2026-09-02T07:00:00Z');
});

it('reads wall-clock time without an offset in its time zone', function (): void {
    $event = CalendarEvent::fromApi(apiEvent([
        'start' => ['date_time' => '2026-10-25T01:30:00', 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => '2026-10-25T04:00:00', 'time_zone' => 'Europe/Amsterdam'],
    ]));

    // 25 Oct 2026: 01:30 is still summer time (UTC+2), 04:00 is winter time (UTC+1).
    expect($event->start->toIso8601ZuluString())->toBe('2026-10-24T23:30:00Z')
        ->and($event->end->toIso8601ZuluString())->toBe('2026-10-25T03:00:00Z');
});

it('reads wall-clock time without an offset or zone as UTC', function (): void {
    $event = CalendarEvent::fromApi(apiEvent([
        'start' => ['date_time' => '2026-09-02T07:00:00'],
    ]));

    expect($event->start->toIso8601ZuluString())->toBe('2026-09-02T07:00:00Z');
});

it('maps an all-day event to midnight UTC', function (): void {
    $event = CalendarEvent::fromApi(apiEvent([
        'all_day' => true,
        'start' => ['date' => '2026-09-02'],
        'end' => ['date' => '2026-09-03'],
    ]));

    expect($event->allDay)->toBeTrue()
        ->and($event->start->toIso8601ZuluString())->toBe('2026-09-02T00:00:00Z')
        ->and($event->end->toIso8601ZuluString())->toBe('2026-09-03T00:00:00Z');
});

it('maps transparency and falls back to opaque', function (mixed $value, Transparency $expected): void {
    expect(CalendarEvent::fromApi(apiEvent(['transparency' => $value]))->transparency)->toBe($expected);
})->with([
    'transparent' => ['transparent', Transparency::Transparent],
    'opaque' => ['opaque', Transparency::Opaque],
    'null' => [null, Transparency::Opaque],
    'unknown' => ['tentative', Transparency::Opaque],
]);

it('maps an occurrence of a series', function (): void {
    $event = CalendarEvent::fromApi(apiEvent(['id' => 'series-1_20260909T070000Z', 'series_uuid' => 'series-1']));

    expect($event->id)->toBe('series-1_20260909T070000Z')
        ->and($event->seriesId)->toBe('series-1')
        ->and($event->isRecurring)->toBeTrue();
});

it('treats an event with a recurrence rule as recurring even without a series uuid', function (): void {
    expect(CalendarEvent::fromApi(apiEvent(['recurrence' => 'FREQ=WEEKLY']))->isRecurring)->toBeTrue()
        ->and(CalendarEvent::fromApi(apiEvent(['recurrence' => '']))->isRecurring)->toBeFalse();
});

it('maps attendees and skips those without an address', function (): void {
    $event = CalendarEvent::fromApi(apiEvent([
        'title' => null,
        'attendees' => [
            ['email' => 'jan@example.com', 'name' => 'Jan', 'status' => 'accepted'],
            ['email' => 'piet@example.com', 'name' => null],
            ['email' => null, 'name' => 'Ghost', 'calendar_address' => 'urn:x'],
        ],
    ]));

    expect($event->title)->toBeNull()
        ->and($event->attendees)->toEqual([
            new Attendee('jan@example.com', 'Jan'),
            new Attendee('piet@example.com'),
        ]);
});

it('round-trips through toArray and fromArray', function (): void {
    $event = CalendarEvent::fromApi(apiEvent([
        'series_uuid' => 'series-1',
        'transparency' => 'transparent',
        'attendees' => [['email' => 'jan@example.com', 'name' => 'Jan']],
    ]));

    $array = $event->toArray();
    $restored = CalendarEvent::fromArray(unserialize(serialize($array)));

    expect($array['start'])->toBe('2026-09-02T07:00:00.000000Z')
        ->and($array['transparency'])->toBe('transparent')
        ->and($restored)->toEqual($event)
        ->and($restored->start->getTimezone()->getName())->toBe('UTC');
});

it('round-trips an event without optional fields', function (): void {
    $event = CalendarEvent::fromApi(apiEvent(['title' => null]));

    expect(CalendarEvent::fromArray($event->toArray()))->toEqual($event);
});

it('round-trips an attendee', function (): void {
    expect(Attendee::fromArray((new Attendee('jan@example.com', 'Jan'))->toArray()))->toEqual(new Attendee('jan@example.com', 'Jan'))
        ->and(Attendee::fromArray(['email' => 'piet@example.com']))->toEqual(new Attendee('piet@example.com'));
});

it('builds the request body in wall-clock time of the event timezone', function (): void {
    $event = new NewEvent(
        title: 'Demo Trivle',
        start: CarbonImmutable::parse('2026-10-20T08:00:00Z'),
        end: CarbonImmutable::parse('2026-10-20T08:30:00Z'),
        timezone: 'Europe/Amsterdam',
        description: 'Intro',
        location: 'Online',
        url: 'https://example.com/meet',
        transparency: Transparency::Opaque,
        attendees: [new Attendee('jan@example.com', 'Jan'), new Attendee('piet@example.com')],
    );

    expect($event->toBody())->toBe([
        'title' => 'Demo Trivle',
        'transparency' => 'opaque',
        'start' => ['date_time' => '2026-10-20T10:00', 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => '2026-10-20T10:30', 'time_zone' => 'Europe/Amsterdam'],
        'description' => 'Intro',
        'location' => 'Online',
        'url' => 'https://example.com/meet',
        'attendees' => [
            ['email' => 'jan@example.com', 'name' => 'Jan'],
            ['email' => 'piet@example.com'],
        ],
    ]);
});

it('keeps microseconds in a round-trip', function (): void {
    $event = new CalendarEvent(
        id: 'evt-1',
        seriesId: null,
        title: null,
        start: CarbonImmutable::parse('2026-09-02T07:00:00.123456Z'),
        end: CarbonImmutable::parse('2026-09-02T08:00:00.654321Z'),
        allDay: false,
        transparency: Transparency::Opaque,
        attendees: [],
        isRecurring: false,
    );

    $array = $event->toArray();
    $restored = CalendarEvent::fromArray($array);

    expect($array['start'])->toBe('2026-09-02T07:00:00.123456Z')
        ->and($restored)->toEqual($event)
        ->and($restored->end->microsecond)->toBe(654321);
});

it('leaves out optional fields that are not set', function (): void {
    $event = new NewEvent(
        title: 'Demo',
        start: CarbonImmutable::parse('2026-10-25T02:30:00Z'),
        end: CarbonImmutable::parse('2026-10-25T03:00:00Z'),
        timezone: 'Europe/Amsterdam',
        transparency: Transparency::Transparent,
    );

    // After the change back to winter time (02:00Z = 03:00 local) the wall clock is unambiguous again.
    expect($event->toBody())->toBe([
        'title' => 'Demo',
        'transparency' => 'transparent',
        'start' => ['date_time' => '2026-10-25T03:30', 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => '2026-10-25T04:00', 'time_zone' => 'Europe/Amsterdam'],
    ]);
});

function eventBetween(string $start, string $end): NewEvent
{
    return new NewEvent('Demo', CarbonImmutable::parse($start), CarbonImmutable::parse($end), 'Europe/Amsterdam');
}

it('sends times right around the DST changes', function (string $start, string $end, string $localStart, string $localEnd): void {
    expect(eventBetween($start, $end)->toBody())->toMatchArray([
        'start' => ['date_time' => $localStart, 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => $localEnd, 'time_zone' => 'Europe/Amsterdam'],
    ]);
})->with([
    'just before the autumn change' => ['2026-10-24T23:30:00Z', '2026-10-24T23:50:00Z', '2026-10-25T01:30', '2026-10-25T01:50'],
    'first minute after the repeated hour' => ['2026-10-25T02:00:00Z', '2026-10-25T02:30:00Z', '2026-10-25T03:00', '2026-10-25T03:30'],
    'before the spring change' => ['2026-03-29T00:30:00Z', '2026-03-29T01:00:00Z', '2026-03-29T01:30', '2026-03-29T03:00'],
]);

it('rejects a time in the repeated hour of the autumn change', function (string $instant): void {
    $event = eventBetween($instant, '2026-10-26T12:00:00Z');

    expect(fn (): array => $event->toBody())->toThrow(InvalidArgumentException::class, 'ambiguous in Europe/Amsterdam');
})->with([
    'first 02:30' => '2026-10-25T00:30:00Z',
    'second 02:30' => '2026-10-25T01:30:00Z',
    'first 02:00' => '2026-10-25T00:00:00Z',
]);

it('rejects an end in the repeated hour too', function (): void {
    $event = eventBetween('2026-10-24T22:00:00Z', '2026-10-25T01:30:00Z');

    expect(fn (): array => $event->toBody())->toThrow(InvalidArgumentException::class, 'ambiguous');
});

it('shifts a local time that does not exist in spring to one that does', function (): void {
    // 02:30 on 29 Mar 2026 does not exist in Amsterdam; Carbon reads it as 03:30, which is fine to send.
    $event = new NewEvent(
        'Demo',
        CarbonImmutable::parse('2026-03-29 02:30', 'Europe/Amsterdam'),
        CarbonImmutable::parse('2026-03-29 04:30', 'Europe/Amsterdam'),
        'Europe/Amsterdam',
    );

    expect($event->toBody()['start'])->toBe(['date_time' => '2026-03-29T03:30', 'time_zone' => 'Europe/Amsterdam']);
});

it('rejects times with seconds or microseconds', function (string $start): void {
    $event = eventBetween($start, '2026-10-20T09:00:00Z');

    expect(fn (): array => $event->toBody())->toThrow(InvalidArgumentException::class, 'has seconds');
})->with([
    'seconds' => '2026-10-20T08:00:30Z',
    'microseconds' => '2026-10-20T08:00:00.5Z',
]);

it('rejects an event that ends before or when it starts', function (string $end): void {
    expect(fn (): NewEvent => eventBetween('2026-10-20T08:00:00Z', $end))
        ->toThrow(InvalidArgumentException::class, 'end of an event must be after its start');
})->with([
    'equal' => '2026-10-20T08:00:00Z',
    'before' => '2026-10-20T07:00:00Z',
]);
