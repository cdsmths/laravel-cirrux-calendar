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
 * One page of the events in a window, with recurring events expanded into occurrences
 * (cirrux.co/help/api/calendar, "List events"):
 * `{"object": "list", "time_min", "time_max", "timezone", "has_more": bool, "next_cursor"?: string, "data": [event, ...]}`
 * where each event is
 * `{"id", "event_uuid", "series_uuid": string|null, "recurrence_id": string|null, "is_exception": bool, "status",
 *   "title": string|null, "all_day": bool, "transparency": string|null, "recurrence": string|null,
 *   "start": {"date_time", "time_zone"} | {"date"}, "end": same, "attendees": [{"email", "name", "status", ...}], ...}`.
 *
 * What the docs settle:
 * - `id` is the occurrence id: the event uuid for a one-off, `<series_uuid>_<recurrence_id>` for an
 *   occurrence of a series. Update and delete take it.
 * - an event is in the window when it overlaps it: it starts before `time_max` and ends after `time_min`.
 * - `date_time` is a UTC instant; the end date of an all-day event is exclusive, and all-day events
 *   run from midnight to midnight in `timezone`.
 * - cancelled events and occurrences are left out.
 * - the window may be at most 366 days; a page holds 250 occurrences unless `limit` (1 to 2500) says otherwise.
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
