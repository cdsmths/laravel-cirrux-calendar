<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Events;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\Paginatable;

/**
 * One page of the events in a window, with recurring events expanded into occurrences.
 *
 * Assumed response (cirrux-co/cli @ da5fd0f, src/commands/calendar/calendar-shared.ts
 * `CalendarEventListResponse` and src/commands/calendar/events/list.ts):
 * `{"object": "list", "time_min", "time_max", "timezone", "has_more": bool, "next_cursor"?: string, "data": [event, ...]}`
 * where each event is
 * `{"id", "event_uuid", "series_uuid": string|null, "recurrence_id": string|null, "is_exception": bool,
 *   "title": string|null, "all_day": bool, "transparency": string|null, "recurrence": string|null,
 *   "start": {"date_time", "time_zone"} | {"date"}, "end": same, "attendees": [{"email": string|null, "name": string|null, ...}], ...}`.
 *
 * Assumptions the CLI does not settle (to be pinned by the contract tests):
 * - `id` is the occurrence id: the event uuid for a single event, `<series-uuid>_<recurrence-id>` for a
 *   series occurrence (CLI `use_occurrence_id` hint); `series_uuid` is then the series uuid, null otherwise.
 * - `date_time` with a `Z` or offset is an instant; without one it is wall-clock time in `time_zone`.
 * - the `end` date of an all-day event is exclusive.
 * - `time_min`/`time_max` are sent as UTC instants; whether the window matches overlap or start is open (plan: open point 3).
 *
 * Use through CirruxConnector::paginate() to follow the cursor; a single send() yields one page.
 */
final class ListEvents extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $calendarUuid,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly string $timezone,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendars/'.rawurlencode($this->calendarUuid).'/events';
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function createDtoFromResponse(Response $response): Collection
    {
        return collect(Arr::array($response->array(), 'data'))
            ->map(static fn (mixed $item): CalendarEvent => CalendarEvent::fromApi(Arr::wrap($item)))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'time_min' => $this->from->utc()->toIso8601ZuluString(),
            'time_max' => $this->to->utc()->toIso8601ZuluString(),
            'timezone' => $this->timezone,
        ];
    }
}
