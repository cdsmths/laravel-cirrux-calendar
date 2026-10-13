<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\DeviceCode;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Http\CirruxAuthConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceAuthorization;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceToken;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\RefreshToken;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function deviceCode(): DeviceCode
{
    return new DeviceCode(
        deviceCode: 'device-secret',
        userCode: 'ABCD-EFGH',
        verificationUri: 'https://auth.cirrux.co/device',
        interval: 5,
        expiresIn: 900,
        codeVerifier: 'verifier-secret',
    );
}

it('talks to the configured base url', function (): void {
    config()->set('cirrux.base_url', 'https://cirrux.test/');
    Saloon::fake([MockResponse::make([])]);

    $response = (new CirruxAuthConnector)->send(new RefreshToken('refresh'));

    expect($response->getPendingRequest()->getUrl())->toBe('https://cirrux.test/api/auth/refresh_token');
});

it('starts the device flow with a pkce challenge and maps the response', function (): void {
    config()->set('cirrux.client_id', 'my-client');
    Saloon::fake([
        DeviceAuthorization::class => MockResponse::make([
            'device_code' => 'device-secret',
            'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://auth.cirrux.co/device',
            'expires_in' => 900,
            'interval' => 5,
        ]),
    ]);

    // The RFC 7636 appendix B example pair.
    $response = (new CirruxAuthConnector)->send(new DeviceAuthorization('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));

    expect($response->getPendingRequest()->body()?->all())->toBe([
        'client_id' => 'my-client',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'code_challenge_method' => 'S256',
    ])->and($response->dto())->toEqual(new DeviceCode(
        deviceCode: 'device-secret',
        userCode: 'ABCD-EFGH',
        verificationUri: 'https://auth.cirrux.co/device',
        interval: 5,
        expiresIn: 900,
        codeVerifier: 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
    ));
});

it('polls the device token with the device code and verifier', function (): void {
    Saloon::fake([
        DeviceToken::class => MockResponse::make([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'session_uuid' => 'session',
            'access_token_expires_in' => 3600,
            'scopes' => ['calendar.read'],
        ]),
    ]);

    $response = (new CirruxAuthConnector)->send(new DeviceToken(deviceCode()));
    $tokens = $response->dto();

    expect($response->getPendingRequest()->body()?->all())->toBe([
        'client_id' => 'cirrux-cli',
        'device_code' => 'device-secret',
        'code_verifier' => 'verifier-secret',
    ])->and($tokens)->toBeInstanceOf(Tokens::class)
        ->and($tokens->accessToken)->toBe('access')
        ->and($tokens->refreshToken)->toBe('refresh')
        ->and($tokens->expiresAt->equalTo(CarbonImmutable::now('UTC')->addHour()))->toBeTrue();
});

it('refreshes with the refresh token and keeps it when Cirrux does not rotate', function (): void {
    Saloon::fake([
        RefreshToken::class => MockResponse::make(['access_token' => 'new-access', 'access_token_expires_in' => 900]),
    ]);

    $response = (new CirruxAuthConnector)->send(new RefreshToken('old-refresh'));
    $tokens = $response->dto();

    expect($response->getPendingRequest()->body()?->all())->toBe(['refresh_token' => 'old-refresh'])
        ->and($tokens)->toBeInstanceOf(Tokens::class)
        ->and($tokens->accessToken)->toBe('new-access')
        ->and($tokens->refreshToken)->toBe('old-refresh');
});
