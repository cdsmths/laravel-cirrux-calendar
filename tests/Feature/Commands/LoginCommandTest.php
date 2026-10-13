<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceAuthorization;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceToken;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function deviceAuthorizationResponse(): MockResponse
{
    return MockResponse::make([
        'device_code' => 'device-secret',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://auth.cirrux.co/device',
        'expires_in' => 900,
        'interval' => 5,
    ]);
}

it('shows the verification url and user code, then stores the tokens', function (): void {
    Saloon::fake([
        DeviceAuthorization::class => deviceAuthorizationResponse(),
        DeviceToken::class => MockResponse::make([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'session_uuid' => 'session',
            'access_token_expires_in' => 3600,
        ]),
    ]);

    $this->artisan('cirrux:login')
        ->expectsOutputToContain('https://auth.cirrux.co/device')
        ->expectsOutputToContain('ABCD-EFGH')
        ->expectsOutputToContain('Logged in to Cirrux.')
        ->assertExitCode(0);

    expect(resolve(TokenStore::class)->get()?->accessToken)->toBe('access');
});

it('fails when the user denies access', function (): void {
    Saloon::fake([
        DeviceAuthorization::class => deviceAuthorizationResponse(),
        DeviceToken::class => MockResponse::make(['error' => 'access_denied']),
    ]);

    $this->artisan('cirrux:login')
        ->expectsOutputToContain('ABCD-EFGH')
        ->expectsOutputToContain('Authorization was denied.')
        ->assertExitCode(1);

    expect(resolve(TokenStore::class)->get())->toBeNull();
});

it('fails when the device code expires', function (): void {
    Saloon::fake([
        DeviceAuthorization::class => deviceAuthorizationResponse(),
        DeviceToken::class => MockResponse::make(['error' => 'expired_token']),
    ]);

    $this->artisan('cirrux:login')
        ->expectsOutputToContain('The device code expired before it was authorized.')
        ->assertExitCode(1);
});

it('fails when Cirrux refuses to start the login', function (): void {
    Saloon::fake([DeviceAuthorization::class => MockResponse::make(['error' => 'invalid_client'], 400)]);

    $this->artisan('cirrux:login')
        ->expectsOutputToContain('Cirrux refused to start the login (HTTP 400).')
        ->assertExitCode(1);

    Saloon::assertSentCount(1);
});
