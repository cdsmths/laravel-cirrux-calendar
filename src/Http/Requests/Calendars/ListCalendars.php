<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Calendars;

use Codesmiths\CirruxCalendar\Data\Calendar;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * The calendars of the logged in user. Cirrux answers with
 * `{"object": "list", "has_more": false, "data": [{"calendar_uuid": "...", "name": "...", "color": "..."}]}`.
 */
final class ListCalendars extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/calendars';
    }

    /**
     * @return Collection<int, Calendar>
     */
    public function createDtoFromResponse(Response $response): Collection
    {
        return collect(Arr::array($response->array(), 'data'))
            ->map(static function (mixed $item): Calendar {
                $calendar = Arr::wrap($item);
                $color = $calendar['color'] ?? null;

                return new Calendar(
                    uuid: Arr::string($calendar, 'calendar_uuid'),
                    name: Arr::string($calendar, 'name'),
                    color: is_string($color) ? $color : null,
                );
            })
            ->values();
    }
}
