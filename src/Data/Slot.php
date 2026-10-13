<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Carbon\CarbonImmutable;

/**
 * A free stretch of time that can be booked. The calculator hands them out in UTC.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * The same instants in another zone. Presentation only: nothing about the slot changes.
     */
    public function inTimezone(string $timezone): self
    {
        return new self($this->start->setTimezone($timezone), $this->end->setTimezone($timezone));
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }
}
