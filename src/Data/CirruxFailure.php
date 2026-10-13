<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Illuminate\Support\Arr;

/**
 * What a failure looked like, without the exception itself: a thrown exception carries a
 * stack trace with closures, which PHP cannot serialize, so event payloads hold this instead.
 */
final readonly class CirruxFailure
{
    /**
     * @param  class-string<CirruxException>  $exception  the class of the exception that was thrown
     * @param  int  $status  the HTTP status, or 0 when there was no response
     * @param  int|null  $retryAfter  seconds until a new request is allowed; only for a rate limit
     */
    public function __construct(
        public string $exception,
        public string $message,
        public int $status,
        public ?int $retryAfter = null,
    ) {}

    public static function from(CirruxException $exception): self
    {
        return new self(
            $exception::class,
            $exception->getMessage(),
            // The exception code is the HTTP status for a request exception, and 0 for the rest.
            $exception->getCode(),
            $exception instanceof RateLimitedException ? $exception->retryAfter : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $attributes  the result of toArray()
     */
    public static function fromArray(array $attributes): self
    {
        $retryAfter = $attributes['retry_after'] ?? null;

        /** @var class-string<CirruxException> $exception */
        $exception = Arr::string($attributes, 'exception');

        return new self(
            $exception,
            Arr::string($attributes, 'message'),
            Arr::integer($attributes, 'status'),
            is_int($retryAfter) ? $retryAfter : null,
        );
    }

    /**
     * @return array{exception: class-string<CirruxException>, message: string, status: int, retry_after: int|null}
     */
    public function toArray(): array
    {
        return [
            'exception' => $this->exception,
            'message' => $this->message,
            'status' => $this->status,
            'retry_after' => $this->retryAfter,
        ];
    }
}
