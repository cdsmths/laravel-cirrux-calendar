<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Events;

use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Illuminate\Support\Arr;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\CursorPaginator;

/**
 * Follows `has_more` / `next_cursor` of ListEvents. `items()` and `collect()` yield CalendarEvent objects.
 *
 * Cirrux names the page size `limit`; the plugin's own cursor paginator would send `per_page`.
 *
 * Fails with a CirruxRequestException when `has_more` is true but `next_cursor` is missing,
 * empty or one that was already used: following it would never end, or end with a partial window.
 */
final class EventsPaginator extends CursorPaginator
{
    /**
     * @var array<string, true>
     */
    private array $usedCursors = [];

    protected function onRewind(): void
    {
        $this->usedCursors = [];
    }

    protected function applyPagination(Request $request): Request
    {
        if ($this->currentResponse instanceof Response) {
            $request->query()->add('cursor', $this->getNextCursor($this->currentResponse));
        }

        if ($this->perPageLimit !== null) {
            $request->query()->add('limit', $this->perPageLimit);
        }

        return $request;
    }

    protected function getNextCursor(Response $response): string
    {
        $cursor = $response->array()['next_cursor'] ?? null;

        if (! is_string($cursor) || $cursor === '') {
            throw new CirruxRequestException('Cirrux reported more events but sent no next_cursor.', $response->status());
        }

        if (isset($this->usedCursors[$cursor])) {
            throw new CirruxRequestException(sprintf('Cirrux sent the cursor "%s" twice; stopping instead of looping.', $cursor), $response->status());
        }

        $this->usedCursors[$cursor] = true;

        return $cursor;
    }

    protected function isLastPage(Response $response): bool
    {
        return Arr::boolean($response->array(), 'has_more') === false;
    }

    /**
     * @return array<int, CalendarEvent>
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return array_map(
            static fn (mixed $item): CalendarEvent => CalendarEvent::fromApi(Arr::wrap($item)),
            array_values(Arr::array($response->array(), 'data')),
        );
    }
}
