<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Tests\Contract;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The events list with a free-form query, so a contract test can see the raw response
 * (`has_more`, `next_cursor`, `series_uuid`, the `time_zone` of each bound, ...).
 */
final class RawListEvents extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  array<string, string|int>  $query
     */
    public function __construct(
        private readonly string $calendarUuid,
        private readonly array $parameters,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendars/'.rawurlencode($this->calendarUuid).'/events';
    }

    /**
     * @return array<string, string|int>
     */
    protected function defaultQuery(): array
    {
        return $this->parameters;
    }
}
