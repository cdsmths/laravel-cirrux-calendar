<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Events;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Deletes an event; Cirrux mails the attendees a cancellation.
 *
 * A bare event uuid deletes the whole event (every occurrence of a series), an occurrence id
 * (`<series-uuid>_<recurrence-id>`) only that occurrence (cirrux-co/cli @ da5fd0f,
 * src/commands/calendar/events/delete.ts). Assumed response: any 2xx without a body the
 * caller needs (the CLI uses `authedRequestVoid` and only checks `response.ok`).
 * A 404 surfaces as CirruxRequestException; treating it as success is the caller's job.
 *
 * Sent once, like every write.
 */
final class DeleteEvent extends Request
{
    public ?int $tries = 1;

    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string $calendarUuid,
        private readonly string $eventId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendars/'.rawurlencode($this->calendarUuid).'/events/'.rawurlencode($this->eventId);
    }
}
