<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Availability\SlotRejection;
use Codesmiths\CirruxCalendar\Exceptions\BookingVetoedException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\InvalidSlotException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Exceptions\SlotUnavailableException;
use Codesmiths\CirruxCalendar\Exceptions\StaleCalendarException;

it('carries the status and previous exception on a request exception', function (): void {
    $previous = new RuntimeException('boom');
    $exception = new CirruxRequestException('Failed.', 502, $previous);

    expect($exception)->toBeInstanceOf(CirruxException::class)
        ->and($exception->status())->toBe(502)
        ->and($exception->getPrevious())->toBe($previous);
});

it('defaults the status of a request exception to zero', function (): void {
    expect((new CirruxRequestException('No response.'))->status())->toBe(0);
});

it('carries the retry delay on a rate limited exception', function (): void {
    $exception = new RateLimitedException(30);

    expect($exception)->toBeInstanceOf(CirruxException::class)
        ->and($exception->retryAfter)->toBe(30)
        ->and($exception->getMessage())->toBe('Cirrux rate limit reached; retry in 30 seconds.');
});

it('names the calendar on a stale calendar exception', function (): void {
    $exception = new StaleCalendarException('cal-1');

    expect($exception)->toBeInstanceOf(CirruxException::class)
        ->and($exception->calendarUuid)->toBe('cal-1')
        ->and($exception->getMessage())->toContain('cal-1')->toContain('cirrux:refresh');
});

it('names the rejection on an invalid slot exception', function (): void {
    $exception = new InvalidSlotException(SlotRejection::OffGrid);

    expect($exception)->toBeInstanceOf(CirruxException::class)
        ->and($exception->reason)->toBe(SlotRejection::OffGrid)
        ->and($exception->getMessage())->toContain('off_grid');
});

it('builds a slot unavailable exception with a reason', function (): void {
    expect(SlotUnavailableException::busy())->toBeInstanceOf(CirruxException::class)
        ->and(SlotUnavailableException::busy()->reason)->toBe('busy')
        ->and(SlotUnavailableException::lockTimeout()->reason)->toBe('lock_timeout')
        ->and(SlotUnavailableException::lockTimeout())->not->toEqual(SlotUnavailableException::busy());
});

it('lets a listener veto a booking with its own message', function (): void {
    $exception = new BookingVetoedException('Blocked by the blocklist.');

    expect($exception)->toBeInstanceOf(CirruxException::class)
        ->and($exception->getMessage())->toBe('Blocked by the blocklist.');
});

it('is a cirrux exception', function (): void {
    expect(new CirruxAuthException('Not logged in.'))->toBeInstanceOf(CirruxException::class);
});
