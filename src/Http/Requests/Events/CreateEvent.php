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
 * Request body: see NewEvent::toBody(). Cirrux answers 201 with the event object, the same shape
 * as one item of ListEvents (cirrux.co/help/api/calendar, "Create an event"). For a one-off
 * event `id` equals `event_uuid` and `series_uuid` is null. An unknown field in the body is
 * refused with 422 `invalid_value`, and a workspace without a subscription gets 402.
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
