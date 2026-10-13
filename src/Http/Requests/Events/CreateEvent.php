<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Events;

use Codesmiths\CirruxCalendar\Data\CalendarEvent;
use Codesmiths\CirruxCalendar\Data\NewEvent;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Creates an event; Cirrux mails every attendee an invitation.
 *
 * Request body: see NewEvent::toBody() (cirrux-co/cli @ da5fd0f, src/commands/calendar/events/event-body.ts).
 * Assumed response: the created event object, the same shape as one item of ListEvents
 * (CLI create.ts reads it as `CalendarEvent` and prints `event.id`). For a non-recurring
 * event `id` equals `event_uuid` and `series_uuid` is null.
 *
 * Sent once: a POST that arrived but answered 5xx must not produce a second event.
 */
final class CreateEvent extends Request implements HasBody
{
    use HasJsonBody;

    public ?int $tries = 1;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $calendarUuid,
        private readonly NewEvent $event,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/calendars/'.rawurlencode($this->calendarUuid).'/events';
    }

    public function createDtoFromResponse(Response $response): CalendarEvent
    {
        return CalendarEvent::fromApi($response->array());
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->event->toBody();
    }
}
