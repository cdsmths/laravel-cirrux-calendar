<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Illuminate\Support\Arr;

final readonly class Calendar
{
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $color,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes  the result of toArray()
     */
    public static function fromArray(array $attributes): self
    {
        $color = $attributes['color'] ?? null;

        return new self(
            uuid: Arr::string($attributes, 'uuid'),
            name: Arr::string($attributes, 'name'),
            color: is_string($color) ? $color : null,
        );
    }

    /**
     * @return array{uuid: string, name: string, color: string|null}
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
