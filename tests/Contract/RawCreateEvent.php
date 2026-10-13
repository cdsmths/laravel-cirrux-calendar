<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Tests\Contract;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Creates an event from a hand-written body, for what NewEvent deliberately cannot
 * express (a `recurrence`). Sent once, like the package's own create.
 */
final class RawCreateEvent extends Request implements HasBody
{
    use HasJsonBody;

    public ?int $tries = 1;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        private readonly string $calendarUuid,
        private readonly array $payload,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendars/'.rawurlencode($this->calendarUuid).'/events';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
