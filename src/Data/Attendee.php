<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Illuminate\Support\Arr;

final readonly class Attendee
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes  the result of toArray()
     */
    public static function fromArray(array $attributes): self
    {
        $name = $attributes['name'] ?? null;

        return new self(
            email: Arr::string($attributes, 'email'),
            name: is_string($name) ? $name : null,
        );
    }

    /**
     * @return array{email: string, name: string|null}
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }
}
