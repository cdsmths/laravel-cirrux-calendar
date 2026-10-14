<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\TokenRefreshed;
use Codesmiths\CirruxCalendar\Events\TokenRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\RefreshToken;
use Illuminate\Support\Facades\Event;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Laravel\Facades\Saloon;

/**
 * Some endpoint behind the connector; the connector does not care which.
 */
final class PingRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/ping';
    }
}

function loggedIn(string $access = 'access-1', string $refresh = 'refresh-1', int $expiresInSeconds = 3600): void
{
    resolve(TokenStore::class)->put(new Tokens($access, $refresh, CarbonImmutable::now('UTC')->addSeconds($expiresInSeconds)));
}

function ping(): Response
{
    return resolve(CirruxConnector::class)->send(new PingRequest);
}

function authorization(Response $response): string
{
    return $response->getPendingRequest()->headers()->get('Authorization');
}

function refreshed(): MockResponse
{
    return MockResponse::make(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'access_token_expires_in' => 3600]);
}

it('talks to the public api with the stored bearer token', function (): void {
    loggedIn();
    Saloon::fake([PingRequest::class => MockResponse::make(['ok' => true])]);

    $response = ping();

    expect($response->getPendingRequest()->getUrl())->toBe('https://api.cirrux.co/public_api/v1/ping')
        ->and(authorization($response))->toBe('Bearer access-1')
        ->and($response->getPendingRequest()->headers()->get('Accept'))->toBe('application/json');
    Saloon::assertSentCount(1);
});

it('puts the error code and description of Cirrux in the request exception', function (array|string $body, ?string $error, string $message): void {
    loggedIn();
    Saloon::fake([PingRequest::class => MockResponse::make($body, 403)]);

    try {
        ping();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe(403)
            ->and($cirruxRequestException->error)->toBe($error)
            ->and($cirruxRequestException->getMessage())->toContain($message);
    }
})->with([
    'code and description' => [['error' => 'calendar_read_only', 'error_description' => 'The calendar is a subscription.'], 'calendar_read_only', 'Cirrux answered 403 calendar_read_only: The calendar is a subscription.'],
    'code only' => [['error' => 'not_authorized'], 'not_authorized', 'Cirrux answered 403 not_authorized.'],
    'no code' => [['message' => 'nope'], null, '403'],
    'not json' => ['<html>Forbidden</html>', null, '403'],
]);

it('copes with a base url without a trailing slash', function (): void {
    config()->set('cirrux.base_url', 'https://cirrux.test');

    expect(resolve(CirruxConnector::class)->resolveBaseUrl())->toBe('https://cirrux.test/public_api/v1');
});

it('refreshes proactively when the token expires within the margin', function (): void {
    Event::fake();
    loggedIn(expiresInSeconds: 59);
    Saloon::fake([RefreshToken::class => refreshed(), PingRequest::class => MockResponse::make(['ok' => true])]);

    $response = ping();

    expect(authorization($response))->toBe('Bearer access-2')
        ->and(resolve(TokenStore::class)->get()?->refreshToken)->toBe('refresh-2');
    Saloon::assertSentCount(2);
    Event::assertDispatchedTimes(TokenRefreshed::class, 1);
});

it('does not refresh a token that is valid beyond the margin', function (): void {
    loggedIn(expiresInSeconds: 120);
    Saloon::fake([PingRequest::class => MockResponse::make(['ok' => true])]);

    ping();

    Saloon::mockClient()->assertSentCount(1, PingRequest::class);
    Saloon::mockClient()->assertSentCount(0, RefreshToken::class);
});

it('fails with an auth exception when nobody is logged in', function (): void {
    Event::fake();
    Saloon::fake([PingRequest::class => MockResponse::make(['ok' => true])]);

    expect(fn (): Response => ping())->toThrow(CirruxAuthException::class, 'Not logged in to Cirrux.');

    Saloon::assertNothingSent();
    Event::assertDispatched(TokenRefreshFailed::class);
});

it('refreshes once on a 401 and retries with the new token', function (): void {
    loggedIn();
    Saloon::fake([
        MockResponse::make(['error' => 'invalid_token'], 401),
        refreshed(),
        MockResponse::make(['ok' => true]),
    ]);

    $response = ping();

    expect($response->status())->toBe(200)
        ->and(authorization($response))->toBe('Bearer access-2')
        ->and(resolve(TokenStore::class)->get()?->accessToken)->toBe('access-2');
    Saloon::assertSentCount(3);
    Saloon::mockClient()->assertSentCount(2, PingRequest::class);
    Saloon::mockClient()->assertSentCount(1, RefreshToken::class);
});

it('gives up when the retry after a refresh is a 401 again', function (): void {
    loggedIn();
    Saloon::fake([
        MockResponse::make([], 401),
        refreshed(),
        MockResponse::make([], 401),
    ]);

    try {
        ping();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe(401);
    }

    Saloon::assertSentCount(3);
});

it('reports a refresh token that was revoked as an auth failure', function (): void {
    Event::fake();
    loggedIn();
    Saloon::fake([
        MockResponse::make([], 401),
        MockResponse::make(['error' => 'invalid_grant'], 401),
    ]);

    expect(fn (): Response => ping())->toThrow(CirruxAuthException::class);

    Saloon::assertSentCount(2);
    Event::assertDispatchedTimes(TokenRefreshFailed::class, 1);
});

it('does not retry other failures', function (int $status): void {
    loggedIn();
    Saloon::fake([MockResponse::make(['error' => 'nope'], $status), MockResponse::make(['ok' => true])]);

    try {
        ping();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe($status)
            ->and($cirruxRequestException->getMessage())->toContain((string) $status);
    }

    Saloon::assertSentCount(1);
})->with([400, 403, 404, 409, 422, 500, 502, 503]);

it('turns a connection failure into a request exception without retrying', function (): void {
    loggedIn();
    Saloon::fake([
        PingRequest::class => MockResponse::make()->throw(
            fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(new RuntimeException('cURL error 28'), $pendingRequest),
        ),
    ]);

    try {
        ping();
        $this->fail('Expected a CirruxRequestException.');
    } catch (CirruxRequestException $cirruxRequestException) {
        expect($cirruxRequestException->status())->toBe(0)
            ->and($cirruxRequestException->getMessage())->toBe('cURL error 28')
            ->and($cirruxRequestException->getPrevious())->toBeInstanceOf(FatalRequestException::class);
    }

    Saloon::mockClient()->assertSentCount(0, RefreshToken::class);
});

it('throws a rate limited exception on a 429 and blocks further requests locally', function (): void {
    loggedIn();
    Saloon::fake([MockResponse::make([], 429, ['Retry-After' => '42']), MockResponse::make(['ok' => true])]);

    try {
        ping();
        $this->fail('Expected a RateLimitedException.');
    } catch (RateLimitedException $rateLimitedException) {
        expect($rateLimitedException->retryAfter)->toBeBetween(40, 42);
    }

    // The limiter remembers the 429, so the second call never reaches Cirrux.
    expect(fn (): Response => ping())->toThrow(RateLimitedException::class);
    Saloon::assertSentCount(1);
});

it('limits requests per minute to the configured rate', function (): void {
    config()->set('cirrux.rate_limit.per_minute', 2);
    loggedIn();
    Saloon::fake([PingRequest::class => MockResponse::make(['ok' => true])]);

    ping();
    ping();

    try {
        ping();
        $this->fail('Expected a RateLimitedException.');
    } catch (RateLimitedException $rateLimitedException) {
        expect($rateLimitedException->retryAfter)->toBeBetween(1, 60);
    }

    Saloon::assertSentCount(2);
});
