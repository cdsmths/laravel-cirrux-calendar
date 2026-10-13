<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

final readonly class Calendar
{
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $color,
    ) {}
}
