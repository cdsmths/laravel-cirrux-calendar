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
 * The calendars of the logged in user (cirrux.co/help/api/calendar, "List calendars"), not paginated:
 * `{"object": "list", "has_more": false, "data": [{"uuid", "calendar_uuid", "mailbox_address", "name", "color", "can_write", ...}]}`.
 *
 * Each item is a calendar's entry in one mailbox. Its `uuid` names that entry; the event endpoints
 * take `calendar_uuid`, so that is the uuid kept here. A calendar linked to two mailboxes comes back
 * twice, with the same `calendar_uuid`.
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
                $mailboxAddress = $calendar['mailbox_address'] ?? null;

                return new Calendar(
                    uuid: Arr::string($calendar, 'calendar_uuid'),
                    name: Arr::string($calendar, 'name'),
                    color: is_string($color) ? $color : null,
                    mailboxAddress: is_string($mailboxAddress) ? $mailboxAddress : null,
                    // Missing means an older answer; only an explicit false says read-only.
                    canWrite: ($calendar['can_write'] ?? true) !== false,
                );
            })
            ->values();
    }
}
