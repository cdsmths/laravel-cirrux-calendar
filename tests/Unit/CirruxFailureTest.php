<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Data\CirruxFailure;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;

it('describes a request exception with its HTTP status', function (): void {
    $failure = CirruxFailure::from(new CirruxRequestException('Boom.', 503));

    expect($failure->exception)->toBe(CirruxRequestException::class)
        ->and($failure->message)->toBe('Boom.')
        ->and($failure->status)->toBe(503)
        ->and($failure->retryAfter)->toBeNull();
});

it('describes a rate limit with the seconds to wait', function (): void {
    $failure = CirruxFailure::from(new RateLimitedException(42));

    expect($failure->exception)->toBe(RateLimitedException::class)
        ->and($failure->status)->toBe(0)
        ->and($failure->retryAfter)->toBe(42);
});

it('describes an exception without a response with status 0', function (): void {
    $failure = CirruxFailure::from(new CirruxAuthException('Not logged in to Cirrux.'));

    expect($failure->exception)->toBe(CirruxAuthException::class)
        ->and($failure->message)->toBe('Not logged in to Cirrux.')
        ->and($failure->status)->toBe(0);
});

it('round-trips through an array', function (CirruxFailure $failure): void {
    expect(CirruxFailure::fromArray($failure->toArray()))->toEqual($failure);
})->with([
    'with retry after' => [fn (): CirruxFailure => CirruxFailure::from(new RateLimitedException(42))],
    'without retry after' => [fn (): CirruxFailure => CirruxFailure::from(new CirruxRequestException('Boom.', 500))],
]);
