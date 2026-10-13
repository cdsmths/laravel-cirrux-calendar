<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Auth\RefreshAccessToken;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\TokenRefreshed;
use Codesmiths\CirruxCalendar\Events\TokenRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Http\CirruxAuthConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\RefreshToken;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

function storeTokens(string $access = 'old-access', string $refresh = 'old-refresh', int $expiresInSeconds = 3600): Tokens
{
    $tokens = new Tokens($access, $refresh, CarbonImmutable::now('UTC')->addSeconds($expiresInSeconds));
    resolve(TokenStore::class)->put($tokens);

    return $tokens;
}

function rotated(): MockResponse
{
    return MockResponse::make([
        'access_token' => 'new-access',
        'refresh_token' => 'new-refresh',
        'access_token_expires_in' => 900,
    ]);
}

it('refreshes a token that expires soon and persists the rotated pair', function (): void {
    Event::fake();
    storeTokens(expiresInSeconds: 30);
    Saloon::fake([RefreshToken::class => rotated()]);

    $tokens = resolve(RefreshAccessToken::class)->handle();

    expect($tokens->accessToken)->toBe('new-access')
        ->and(resolve(TokenStore::class)->get()?->refreshToken)->toBe('new-refresh');

    Saloon::assertSentCount(1);
    Saloon::assertSent(fn (RefreshToken $request): bool => $request->body()->all() === ['refresh_token' => 'old-refresh']);
    Event::assertDispatchedTimes(TokenRefreshed::class, 1);
    Event::assertDispatched(TokenRefreshed::class, fn (TokenRefreshed $event): bool => $event->expiresAt->equalTo($tokens->expiresAt));
    Event::assertNotDispatched(TokenRefreshFailed::class);
});

it('leaves a token that is valid beyond the margin alone', function (): void {
    Event::fake();
    storeTokens(expiresInSeconds: 61);
    Saloon::fake([RefreshToken::class => rotated()]);

    $tokens = resolve(RefreshAccessToken::class)->handle();

    expect($tokens->accessToken)->toBe('old-access');
    Saloon::assertNothingSent();
    Event::assertNotDispatched(TokenRefreshed::class);
});

it('refreshes a valid token that Cirrux rejected', function (): void {
    storeTokens();
    Saloon::fake([RefreshToken::class => rotated()]);

    $tokens = resolve(RefreshAccessToken::class)->handle(rejectedAccessToken: 'old-access');

    expect($tokens->accessToken)->toBe('new-access');
    Saloon::assertSentCount(1);
});

it('does not refresh again when another process already replaced the rejected token', function (): void {
    Event::fake();
    storeTokens(access: 'already-refreshed', refresh: 'rotated-refresh');
    Saloon::fake([RefreshToken::class => rotated()]);

    $tokens = resolve(RefreshAccessToken::class)->handle(rejectedAccessToken: 'old-access');

    expect($tokens->accessToken)->toBe('already-refreshed');
    Saloon::assertNothingSent();
    Event::assertNotDispatched(TokenRefreshed::class);
});

it('rereads the store after taking the lock, so a refresh that finished while waiting is reused', function (): void {
    Event::fake();
    storeTokens(expiresInSeconds: 10);
    Saloon::fake([RefreshToken::class => rotated()]);

    // The other process finishes its refresh while this one waits for the lock.
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andReturnUsing(function (int $seconds, Closure $callback): mixed {
        storeTokens(access: 'refreshed-elsewhere', refresh: 'rotated-elsewhere');

        return $callback();
    });
    $locks = Mockery::mock(LockProvider::class);
    $locks->shouldReceive('lock')->with('cirrux:token-refresh', 30)->once()->andReturn($lock);

    $action = new RefreshAccessToken(resolve(TokenStore::class), resolve(CirruxAuthConnector::class), $locks);
    $tokens = $action->handle();

    expect($tokens->accessToken)->toBe('refreshed-elsewhere');
    Saloon::assertNothingSent();
    Event::assertNotDispatched(TokenRefreshed::class);
});

it('takes the lock from the configured cache store', function (): void {
    storeTokens(expiresInSeconds: 10);
    Saloon::fake([RefreshToken::class => rotated()]);
    $held = Cache::lock('cirrux:token-refresh', 30);
    $held->get();

    config()->set('cirrux.lock.timeout', 0);

    expect(fn (): Tokens => resolve(RefreshAccessToken::class)->handle())
        ->toThrow(CirruxRequestException::class, 'Timed out waiting for another process to refresh the Cirrux token.');

    Saloon::assertNothingSent();
    $held->release();
    expect(resolve(RefreshAccessToken::class)->handle()->accessToken)->toBe('new-access');
});

it('fails definitively when Cirrux rejects the refresh token', function (int $status): void {
    Event::fake();
    storeTokens(expiresInSeconds: 10);
    Saloon::fake([RefreshToken::class => MockResponse::make(['error' => 'invalid_grant'], $status)]);

    try {
        resolve(RefreshAccessToken::class)->handle();
        $this->fail('Expected a CirruxAuthException.');
    } catch (CirruxAuthException $cirruxAuthException) {
        expect($cirruxAuthException->getMessage())->toBe(sprintf('Cirrux rejected the refresh token (HTTP %d).', $status));
    }

    expect(resolve(TokenStore::class)->get()?->refreshToken)->toBe('old-refresh');
    Event::assertDispatchedTimes(TokenRefreshFailed::class, 1);
    Event::assertDispatched(TokenRefreshFailed::class, fn (TokenRefreshFailed $event): bool => $event->failure->message === sprintf('Cirrux rejected the refresh token (HTTP %d).', $status)
        && $event->failure->exception === CirruxAuthException::class
        && $event->failure->status === 0
        && $event->failure->retryAfter === null);
    Event::assertNotDispatched(TokenRefreshed::class);
})->with([400, 401, 422]);

it('fails definitively when nobody is logged in', function (): void {
    Event::fake();
    Saloon::fake([RefreshToken::class => rotated()]);

    expect(fn (): Tokens => resolve(RefreshAccessToken::class)->handle())
        ->toThrow(CirruxAuthException::class, 'Not logged in to Cirrux.');

    Saloon::assertNothingSent();
    Event::assertDispatchedTimes(TokenRefreshFailed::class, 1);
});

it('keeps the tokens and stays quiet when the refresh fails for a passing reason', function (int $status): void {
    Event::fake();
    storeTokens(expiresInSeconds: 10);
    Saloon::fake([RefreshToken::class => MockResponse::make('', $status)]);

    try {
        resolve(RefreshAccessToken::class)->handle();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe($status);
    }

    expect(resolve(TokenStore::class)->get()?->refreshToken)->toBe('old-refresh');
    Event::assertNotDispatched(TokenRefreshFailed::class);
})->with([429, 500, 503]);

it('turns a connection failure into a request exception', function (): void {
    Event::fake();
    storeTokens(expiresInSeconds: 10);
    Saloon::fake([
        RefreshToken::class => MockResponse::make()->throw(
            fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(new RuntimeException('cURL error 6'), $pendingRequest),
        ),
    ]);

    try {
        resolve(RefreshAccessToken::class)->handle();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe(0)
            ->and($cirruxRequestException->getPrevious())->toBeInstanceOf(FatalRequestException::class);
    }

    Event::assertNotDispatched(TokenRefreshFailed::class);
});

it('refuses a cache store without atomic locks', function (): void {
    Cache::extend('lockless', fn (): Repository => new Repository(Mockery::mock(Store::class)));
    config()->set('cache.stores.lockless', ['driver' => 'lockless']);
    config()->set('cirrux.cache.store', 'lockless');

    resolve(RefreshAccessToken::class);
})->throws(LogicException::class, 'The cirrux.cache.store cache store does not support atomic locks.');
