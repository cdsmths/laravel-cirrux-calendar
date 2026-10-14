<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Booking\BookSlot;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Availability\SlotRejection;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Data\Attendee;
use Codesmiths\CirruxCalendar\Data\Booking;
use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\BookingCreated;
use Codesmiths\CirruxCalendar\Events\BookingCreating;
use Codesmiths\CirruxCalendar\Events\BookingRejected;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshed;
use Codesmiths\CirruxCalendar\Events\CalendarRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\BookingVetoedException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidSlotException;
use Codesmiths\CirruxCalendar\Exceptions\SlotUnavailableException;
use Codesmiths\CirruxCalendar\Facades\Cirrux;
use Codesmiths\CirruxCalendar\Http\Requests\Events\CreateEvent as CreateEventRequest;
use Codesmiths\CirruxCalendar\Http\Requests\Events\DeleteEvent as DeleteEventRequest;
use Codesmiths\CirruxCalendar\Http\Requests\Events\ListEvents;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

// Wednesday 2026-10-28 12:00Z. The first valid demo start is Friday 2026-10-30 09:00 CET = 08:00Z.
const BOOK_SLOT_START = '2026-10-30T08:00:00Z';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    config()->set('cirrux.event_types.demo.calendar', 'cal-1');
    resolve(TokenStore::class)->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));
    Event::fake([BookingCreating::class, BookingCreated::class, BookingRejected::class]);
});

/**
 * @return array<string, mixed>
 */
function bookSlotPayload(string $id, string $start, string $end): array
{
    return [
        'id' => $id,
        'event_uuid' => $id,
        'series_uuid' => null,
        'title' => 'Event '.$id,
        'start' => ['date_time' => $start, 'time_zone' => 'UTC'],
        'end' => ['date_time' => $end, 'time_zone' => 'UTC'],
        'attendees' => [],
    ];
}

/**
 * @param  list<array<string, mixed>>  $events
 */
function bookSlotList(array $events = []): MockResponse
{
    return MockResponse::make(['has_more' => false, 'data' => $events]);
}

function bookSlotStart(): CarbonImmutable
{
    return CarbonImmutable::parse(BOOK_SLOT_START);
}

function bookSlotAttendee(): Attendee
{
    return new Attendee('jan@example.com', 'Jan');
}

it('books a free slot: one fresh list, one create, a row and BookingCreated', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList([bookSlotPayload('elsewhere', '2026-10-30T12:00:00Z', '2026-10-30T13:00:00Z')]),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    $booking = Cirrux::book('demo', bookSlotStart()->setTimezone('Asia/Tokyo'), bookSlotAttendee(), 'Bring a laptop');

    expect($booking)->toBeInstanceOf(Booking::class)
        ->and($booking->eventId)->toBe('evt-1')
        ->and($booking->calendarUuid)->toBe('cal-1')
        ->and($booking->eventType)->toBe('demo')
        ->and($booking->start->toIso8601ZuluString())->toBe('2026-10-30T08:00:00Z')
        ->and($booking->end->toIso8601ZuluString())->toBe('2026-10-30T08:30:00Z')
        ->and($booking->attendee)->toEqual(bookSlotAttendee())
        ->and($booking->notes)->toBe('Bring a laptop')
        ->and($booking->status)->toBe(BookingStatus::Confirmed);

    $record = BookingRecord::query()->find($booking->id);

    expect(BookingRecord::query()->count())->toBe(1)
        ->and($record?->toBooking())->toEqual($booking);

    Saloon::assertSentCount(2);
    Saloon::assertSent(fn (CreateEventRequest $request): bool => $request->body()->all() === [
        'title' => 'Demo',
        'transparency' => 'opaque',
        'start' => ['date_time' => '2026-10-30T08:00:00Z', 'time_zone' => 'Europe/Amsterdam'],
        'end' => ['date_time' => '2026-10-30T08:30:00Z', 'time_zone' => 'Europe/Amsterdam'],
        'description' => 'Bring a laptop',
        'attendees' => [['email' => 'jan@example.com', 'name' => 'Jan']],
    ]);
    Event::assertDispatched(BookingCreating::class, fn (BookingCreating $event): bool => $event->eventType === 'demo'
        && $event->slot->start->toIso8601ZuluString() === BOOK_SLOT_START
        && $event->notes === 'Bring a laptop'
        && $event->title === 'Demo');
    Event::assertDispatched(BookingCreated::class, fn (BookingCreated $event): bool => $event->booking === $booking);
    Event::assertNotDispatched(BookingRejected::class);
});

it('gives the event the title of this booking, trimmed', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    Cirrux::book('demo', bookSlotStart(), bookSlotAttendee(), title: '  Demo Campmaster / La Brouquère ');

    Saloon::assertSent(fn (CreateEventRequest $request): bool => $request->body()->get('title') === 'Demo Campmaster / La Brouquère');
    Event::assertDispatched(BookingCreating::class, fn (BookingCreating $event): bool => $event->title === 'Demo Campmaster / La Brouquère');
});

it('puts the url and location of the event type on the event', function (): void {
    config()->set('cirrux.event_types.demo.url', 'https://meet.example.com/demo');
    config()->set('cirrux.event_types.demo.location', 'Online');
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    Cirrux::book('demo', bookSlotStart(), bookSlotAttendee());

    Saloon::assertSent(fn (CreateEventRequest $request): bool => $request->body()->get('url') === 'https://meet.example.com/demo'
        && $request->body()->get('location') === 'Online');
    Event::assertDispatched(BookingCreating::class, fn (BookingCreating $event): bool => $event->url === 'https://meet.example.com/demo'
        && $event->location === 'Online');
});

it('lets a booking pass its own url and location, trimmed, and falls back on empty ones', function (?string $url, ?string $location, ?string $expectedUrl, ?string $expectedLocation): void {
    config()->set('cirrux.event_types.demo.url', 'https://meet.example.com/demo');
    config()->set('cirrux.event_types.demo.location', 'Online');
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    Cirrux::book('demo', bookSlotStart(), bookSlotAttendee(), url: $url, location: $location);

    Saloon::assertSent(fn (CreateEventRequest $request): bool => $request->body()->get('url') === $expectedUrl
        && $request->body()->get('location') === $expectedLocation);
})->with([
    'own values' => [' https://zoom.example.com/j/123 ', ' Zoom ', 'https://zoom.example.com/j/123', 'Zoom'],
    'empty values' => ['', '   ', 'https://meet.example.com/demo', 'Online'],
    'null values' => [null, null, 'https://meet.example.com/demo', 'Online'],
]);

it('sends no url or location when neither the event type nor the booking has one', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    Cirrux::book('demo', bookSlotStart(), bookSlotAttendee());

    Saloon::assertSent(fn (CreateEventRequest $request): bool => ! array_key_exists('url', $request->body()->all()) && ! array_key_exists('location', $request->body()->all()));
});

it('refuses a url that is not http or https before calling Cirrux', function (): void {
    Saloon::fake([]);

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee(), url: 'javascript:alert(1)'))
        ->toThrow(InvalidArgumentException::class, 'http or https');

    Saloon::assertNothingSent();
    Event::assertNotDispatched(BookingRejected::class);
});

it('falls back to the title of the event type without a usable title', function (?string $title): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    resolve(BookSlot::class)->handle('demo', bookSlotStart(), bookSlotAttendee(), title: $title);

    Saloon::assertSent(fn (CreateEventRequest $request): bool => $request->body()->get('title') === 'Demo');
    Event::assertDispatched(BookingCreating::class, fn (BookingCreating $event): bool => $event->title === 'Demo');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["  \t\n "],
]);

it('asks Cirrux once for the fresh check, whatever availability read before', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    Cirrux::availability('demo')->between(CarbonImmutable::now('UTC'), CarbonImmutable::now('UTC')->addDays(7))->slots();
    Cirrux::book('demo', bookSlotStart(), bookSlotAttendee());

    Saloon::assertSentCount(2);
    Saloon::assertSent(ListEvents::class);
});

it('refuses an invalid start without a single API call', function (string $start, SlotRejection $reason): void {
    Saloon::fake([]);

    try {
        Cirrux::book('demo', CarbonImmutable::parse($start), bookSlotAttendee());
        $this->fail('Expected an InvalidSlotException.');
    } catch (InvalidSlotException $invalidSlotException) {
        expect($invalidSlotException->reason)->toBe($reason);
    }

    Saloon::assertSentCount(0);
    expect(BookingRecord::query()->count())->toBe(0);
    Event::assertDispatched(BookingRejected::class, fn (BookingRejected $event): bool => $event->reason === $reason->value
        && $event->eventType === 'demo'
        && $event->start->toIso8601ZuluString() === CarbonImmutable::parse($start)->toIso8601ZuluString()
        && $event->attendee->email === 'jan@example.com');
    Event::assertNotDispatched(BookingCreating::class);
    Event::assertNotDispatched(BookingCreated::class);
})->with([
    'in the past' => ['2026-10-23T08:00:00Z', SlotRejection::InPast],
    'too soon' => ['2026-10-29T11:00:00Z', SlotRejection::TooSoon],
    'too far ahead' => ['2026-12-04T08:00:00Z', SlotRejection::TooFarAhead],
    'off the grid' => ['2026-10-30T08:15:00Z', SlotRejection::OffGrid],
    'outside the hours' => ['2026-10-31T08:00:00Z', SlotRejection::OutsideHours],
]);

it('refuses an ambiguous wall-clock time', function (): void {
    config()->set('cirrux.event_types.demo.hours', ['sun' => [['02:00', '04:00']]]);
    config()->set('cirrux.event_types.demo.min_notice', 0);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    Saloon::fake([]);

    // 02:30 on Sunday 2026-10-25 happens twice in Amsterdam.
    expect(fn (): Booking => Cirrux::book('demo', CarbonImmutable::parse('2026-10-25T00:30:00Z'), bookSlotAttendee()))
        ->toThrow(InvalidSlotException::class);

    Event::assertDispatched(BookingRejected::class, fn (BookingRejected $event): bool => $event->reason === SlotRejection::AmbiguousTime->value);
    Saloon::assertSentCount(0);
});

it('refuses a busy slot, buffers included, without creating anything', function (string $start, string $end): void {
    Saloon::fake([ListEvents::class => bookSlotList([bookSlotPayload('busy', $start, $end)])]);

    try {
        Cirrux::book('demo', bookSlotStart(), bookSlotAttendee());
        $this->fail('Expected a SlotUnavailableException.');
    } catch (SlotUnavailableException $slotUnavailableException) {
        expect($slotUnavailableException->reason)->toBe('busy');
    }

    Saloon::assertSentCount(1);
    Saloon::assertNotSent(CreateEventRequest::class);
    expect(BookingRecord::query()->count())->toBe(0);
    Event::assertDispatched(BookingRejected::class, fn (BookingRejected $event): bool => $event->reason === 'busy');
    Event::assertNotDispatched(BookingCreating::class);
    Event::assertNotDispatched(BookingCreated::class);
})->with([
    'an overlapping event' => ['2026-10-30T08:00:00Z', '2026-10-30T09:00:00Z'],
    'a buffer conflict' => ['2026-10-30T08:35:00Z', '2026-10-30T09:00:00Z'],
]);

it('refuses a slot while another process holds the calendar lock', function (): void {
    config()->set('cirrux.lock.timeout', 0);
    Saloon::fake([]);
    $lock = Cache::lock('cirrux:lock:cal-1', 60);
    expect($lock->get())->toBeTrue();

    try {
        Cirrux::book('demo', bookSlotStart(), bookSlotAttendee());
        $this->fail('Expected a SlotUnavailableException.');
    } catch (SlotUnavailableException $slotUnavailableException) {
        expect($slotUnavailableException->reason)->toBe('lock_timeout');
    } finally {
        $lock->release();
    }

    Saloon::assertSentCount(0);
    Event::assertDispatched(BookingRejected::class, fn (BookingRejected $event): bool => $event->reason === 'lock_timeout');
});

it('releases the lock after a booking', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
    ]);

    Cirrux::book('demo', bookSlotStart(), bookSlotAttendee());

    $lock = Cache::lock('cirrux:lock:cal-1', 60);

    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('lets a listener veto the booking before anything is created', function (): void {
    Event::fake([BookingRejected::class, BookingCreated::class]);
    Event::listen(BookingCreating::class, function (): never {
        throw new BookingVetoedException('Not for you.');
    });
    Saloon::fake([ListEvents::class => bookSlotList()]);

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(BookingVetoedException::class, 'Not for you.');

    Saloon::assertNotSent(CreateEventRequest::class);
    expect(BookingRecord::query()->count())->toBe(0);
    Event::assertDispatched(BookingRejected::class, fn (BookingRejected $event): bool => $event->reason === 'vetoed');
    Event::assertNotDispatched(BookingCreated::class);

    // The veto must not leave the calendar locked.
    $lock = Cache::lock('cirrux:lock:cal-1', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('deletes the created event again when the row cannot be stored', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
        DeleteEventRequest::class => MockResponse::make('', 204),
    ]);
    BookingRecord::creating(function (): never {
        throw new RuntimeException('database is gone');
    });

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(RuntimeException::class, 'database is gone');

    Saloon::assertSent(fn (DeleteEventRequest $request): bool => str_ends_with($request->resolveEndpoint(), '/evt-1'));
    Saloon::assertSentCount(3);
    Event::assertNotDispatched(BookingCreated::class);
    expect(BookingRecord::query()->count())->toBe(0);

    BookingRecord::flushEventListeners();
});

it('throws the original exception when the compensating delete fails too', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 201),
        DeleteEventRequest::class => MockResponse::make(['error' => 'boom'], 500),
    ]);
    BookingRecord::creating(function (): never {
        throw new RuntimeException('database is gone');
    });
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $throwable) use (&$reported): bool {
        $reported[] = $throwable;

        return false;
    });

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(RuntimeException::class, 'database is gone');

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(CirruxRequestException::class);
    Event::assertNotDispatched(BookingCreated::class);

    BookingRecord::flushEventListeners();
});

it('leaves no row and no BookingCreated when Cirrux refuses the create', function (): void {
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make(['error' => 'boom'], 500),
    ]);

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(CirruxRequestException::class);

    expect(BookingRecord::query()->count())->toBe(0);
    Event::assertNotDispatched(BookingCreated::class);
    Saloon::assertNotSent(DeleteEventRequest::class);
});

it('fails when the fresh check cannot read the calendar', function (): void {
    Saloon::fake([ListEvents::class => MockResponse::make(['error' => 'boom'], 500)]);

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(CirruxRequestException::class);

    Saloon::assertNotSent(CreateEventRequest::class);
});

it('refuses an unknown event type and one without a calendar', function (): void {
    Saloon::fake([]);

    expect(fn (): Booking => resolve(BookSlot::class)->handle('nope', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.nope" must be an array');

    config()->set('cirrux.event_types.demo.calendar');

    expect(fn (): Booking => resolve(BookSlot::class)->handle('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(InvalidConfigurationException::class, '"cirrux.event_types.demo.calendar" must be the uuid of a calendar');

    Saloon::assertSentCount(0);
});

it('refreshes after the lock when the compensating delete forgot the calendar', function (): void {
    Event::fake([BookingCreating::class, BookingCreated::class, BookingRejected::class, CalendarRefreshed::class, CalendarRefreshFailed::class]);
    config()->set('cirrux.lock.timeout', 0);
    $cache = resolve(EventCache::class);
    [$from, $to] = $cache->refreshWindow();
    $cache->replace('cal-1', $from, $to, collect(), CarbonImmutable::now('UTC'));
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make([...bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 'recurrence' => 'FREQ=WEEKLY'], 201),
        DeleteEventRequest::class => MockResponse::make('', 204),
    ]);
    BookingRecord::creating(function (): never {
        throw new RuntimeException('database is gone');
    });

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(RuntimeException::class, 'database is gone');

    // The fresh check and the refresh both list; the refresh ran with the lock released.
    Saloon::assertSentCount(4);
    Event::assertDispatchedTimes(CalendarRefreshed::class, 1);
    Event::assertNotDispatched(CalendarRefreshFailed::class);

    BookingRecord::flushEventListeners();
});

it('lets the original exception win when the refresh after the compensating delete fails', function (): void {
    Event::fake([BookingCreating::class, BookingCreated::class, BookingRejected::class, CalendarRefreshFailed::class]);
    $cache = resolve(EventCache::class);
    [$from, $to] = $cache->refreshWindow();
    $cache->replace('cal-1', $from, $to, collect(), CarbonImmutable::now('UTC'));
    $lists = 0;
    Saloon::fake([
        CreateEventRequest::class => MockResponse::make([...bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 'recurrence' => 'FREQ=WEEKLY'], 201),
        DeleteEventRequest::class => MockResponse::make('', 204),
        // The fresh check is the first list, the refresh after the lock the second.
        ListEvents::class => function () use (&$lists): MockResponse {
            $lists++;

            return $lists === 1 ? bookSlotList() : MockResponse::make(['error' => 'boom'], 500);
        },
    ]);
    BookingRecord::creating(function (): never {
        throw new RuntimeException('database is gone');
    });

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(RuntimeException::class, 'database is gone');

    Event::assertDispatchedTimes(CalendarRefreshFailed::class, 1);

    BookingRecord::flushEventListeners();
});

it('throws the insert failure when the reconciliation after the compensating delete fails', function (): void {
    $cache = resolve(EventCache::class);
    [$from, $to] = $cache->refreshWindow();
    $cache->replace('cal-1', $from, $to, collect(), CarbonImmutable::now('UTC'));
    BookingRecord::query()->create([
        'event_type' => 'demo',
        'calendar_uuid' => 'cal-1',
        'event_id' => 'evt-other',
        'start_at' => '2026-11-03 08:00:00',
        'end_at' => '2026-11-03 08:30:00',
        'attendee_email' => 'other@example.com',
        'status' => BookingStatus::Confirmed,
    ]);
    $armed = false;
    $lists = 0;
    Saloon::fake([
        ListEvents::class => function () use (&$armed, &$lists): MockResponse {
            // The first list is the fresh check, the second the refresh that reconciles.
            $armed = ++$lists > 1;

            return bookSlotList();
        },
        CreateEventRequest::class => MockResponse::make([...bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 'recurrence' => 'FREQ=WEEKLY'], 201),
        DeleteEventRequest::class => MockResponse::make('', 204),
    ]);
    BookingRecord::creating(function (): never {
        throw new RuntimeException('insert failed');
    });
    BookingRecord::retrieved(function () use (&$armed): void {
        throw_if($armed, QueryException::class, 'sqlite', 'select 1', [], new RuntimeException('reconcile failed'));
    });
    $reported = [];
    resolve(ExceptionHandler::class)->reportable(function (Throwable $throwable) use (&$reported): bool {
        $reported[] = $throwable;

        return false;
    });

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(RuntimeException::class, 'insert failed');

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(QueryException::class);

    BookingRecord::flushEventListeners();
});

it('throws the insert failure when a CalendarRefreshed listener throws after the compensating delete', function (): void {
    $cache = resolve(EventCache::class);
    [$from, $to] = $cache->refreshWindow();
    $cache->replace('cal-1', $from, $to, collect(), CarbonImmutable::now('UTC'));
    Saloon::fake([
        ListEvents::class => bookSlotList(),
        CreateEventRequest::class => MockResponse::make([...bookSlotPayload('evt-1', BOOK_SLOT_START, '2026-10-30T08:30:00Z'), 'recurrence' => 'FREQ=WEEKLY'], 201),
        DeleteEventRequest::class => MockResponse::make('', 204),
    ]);
    BookingRecord::creating(function (): never {
        throw new RuntimeException('insert failed');
    });
    Event::listen(CalendarRefreshed::class, function (): never {
        throw new RuntimeException('listener broke');
    });
    $reported = [];
    resolve(ExceptionHandler::class)->reportable(function (Throwable $throwable) use (&$reported): bool {
        $reported[] = $throwable;

        return false;
    });

    expect(fn (): Booking => Cirrux::book('demo', bookSlotStart(), bookSlotAttendee()))
        ->toThrow(RuntimeException::class, 'insert failed');

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->getMessage())->toBe('listener broke');

    BookingRecord::flushEventListeners();
});
