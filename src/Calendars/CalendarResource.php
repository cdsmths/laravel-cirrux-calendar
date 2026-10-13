<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Calendars;

use Codesmiths\CirruxCalendar\Actions\Events\CreateEvent;
use Codesmiths\CirruxCalendar\Actions\Events\DeleteEvent;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Illuminate\Contracts\Container\Container;

/**
 * One calendar, addressed by its uuid. Nothing is sent until an event call asks for it.
 */
final readonly class CalendarResource
{
    public function __construct(
        public string $uuid,
        private Container $container,
    ) {}

    public function events(): EventQuery
    {
        return EventQuery::for(
            $this->uuid,
            $this->container->make(CirruxConnector::class),
            $this->container->make(EventCache::class),
            $this->container->make(CreateEvent::class),
            $this->container->make(DeleteEvent::class),
        );
    }
}
