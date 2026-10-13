<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Events\LoggedIn;
use Codesmiths\CirruxCalendar\Events\LoggedOut;
use Codesmiths\CirruxCalendar\Events\TokenRefreshed;
use Codesmiths\CirruxCalendar\Events\TokenRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Illuminate\Support\Facades\Event;

it('dispatches the auth events through the dispatchable trait', function (): void {
    Event::fake();
    $expiresAt = CarbonImmutable::now('UTC')->addHour();
    $exception = new CirruxAuthException('Session expired.');

    event(new LoggedIn($expiresAt));
    event(new LoggedOut());
    event(new TokenRefreshed($expiresAt));
    event(new TokenRefreshFailed($exception));

    Event::assertDispatched(LoggedIn::class, fn (LoggedIn $event): bool => $event->expiresAt === $expiresAt);
    Event::assertDispatched(LoggedOut::class);
    Event::assertDispatched(TokenRefreshed::class, fn (TokenRefreshed $event): bool => $event->expiresAt === $expiresAt);
    Event::assertDispatched(TokenRefreshFailed::class, fn (TokenRefreshFailed $event): bool => $event->exception === $exception);
});

it('serializes the payloads for queued listeners', function (): void {
    $expiresAt = CarbonImmutable::now('UTC')->addHour();

    $loggedIn = unserialize(serialize(new LoggedIn($expiresAt)));
    $loggedOut = unserialize(serialize(new LoggedOut));

    expect($loggedIn)->toBeInstanceOf(LoggedIn::class)
        ->and($loggedIn->expiresAt->equalTo($expiresAt))->toBeTrue()
        ->and($loggedOut)->toBeInstanceOf(LoggedOut::class);
});
