<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Illuminate\Support\Arr;

final readonly class Calendar
{
    /**
     * @param  string  $uuid  the `calendar_uuid`: the id every event endpoint takes
     * @param  string|null  $mailboxAddress  the mailbox this entry of the calendar belongs to; a user with
     *                                       several mailboxes often has several calendars named "Calendar"
     * @param  bool  $canWrite  whether you may create and delete events here; false for a viewer and for a
     *                          subscription to an external feed, which cannot take bookings
     */
    public function __construct(
        public string $uuid,
        public string $name,
        public ?string $color,
        public ?string $mailboxAddress = null,
        public bool $canWrite = true,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes  the result of toArray()
     */
    public static function fromArray(array $attributes): self
    {
        $color = $attributes['color'] ?? null;
        $mailboxAddress = $attributes['mailbox_address'] ?? null;
        $canWrite = $attributes['can_write'] ?? true;

        return new self(
            uuid: Arr::string($attributes, 'uuid'),
            name: Arr::string($attributes, 'name'),
            color: is_string($color) ? $color : null,
            mailboxAddress: is_string($mailboxAddress) ? $mailboxAddress : null,
            canWrite: $canWrite !== false,
        );
    }

    /**
     * @return array{uuid: string, name: string, color: string|null, mailbox_address: string|null, can_write: bool}
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'color' => $this->color,
            'mailbox_address' => $this->mailboxAddress,
            'can_write' => $this->canWrite,
        ];
    }
}
