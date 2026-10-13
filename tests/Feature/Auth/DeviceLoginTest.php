<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Actions\Auth\CompleteDeviceLogin;
use Codesmiths\CirruxCalendar\Actions\Auth\Logout;
use Codesmiths\CirruxCalendar\Actions\Auth\StartDeviceLogin;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\DeviceCode;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\LoggedIn;
use Codesmiths\CirruxCalendar\Events\LoggedOut;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceAuthorization;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function pendingDevice(int $interval = 5, int $expiresIn = 900): DeviceCode
{
    return new DeviceCode(
        deviceCode: 'device-secret',
        userCode: 'ABCD-EFGH',
        verificationUri: 'https://auth.cirrux.co/device',
        interval: $interval,
        expiresIn: $expiresIn,
        codeVerifier: 'verifier-secret',
    );
}

function tokenResponse(): MockResponse
{
    return MockResponse::make([
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'session_uuid' => 'session',
        'access_token_expires_in' => 3600,
    ]);
}

function pending(string $error = 'authorization_pending'): MockResponse
{
    return MockResponse::make(['error' => $error]);
}

describe('StartDeviceLogin', function (): void {
    it('returns the device code with the pkce verifier it sent a challenge for', function (): void {
        Saloon::fake([
            DeviceAuthorization::class => MockResponse::make([
                'device_code' => 'device-secret',
                'user_code' => 'ABCD-EFGH',
                'verification_uri' => 'https://auth.cirrux.co/device',
                'expires_in' => 900,
                'interval' => 5,
            ]),
        ]);

        $device = resolve(StartDeviceLogin::class)->handle();

        expect($device)->toBeInstanceOf(DeviceCode::class)
            ->and($device->userCode)->toBe('ABCD-EFGH')
            ->and($device->verificationUri)->toBe('https://auth.cirrux.co/device')
            ->and($device->codeVerifier)->toHaveLength(64);

        Saloon::assertSent(fn (DeviceAuthorization $request): bool => $request->body()->get('code_challenge')
            === rtrim(strtr(base64_encode(hash('sha256', $device->codeVerifier, true)), '+/', '-_'), '='));
    });

    it('fails with an auth exception when Cirrux refuses', function (): void {
        Saloon::fake([DeviceAuthorization::class => MockResponse::make(['error' => 'invalid_client'], 400)]);

        resolve(StartDeviceLogin::class)->handle();
    })->throws(CirruxAuthException::class, 'Cirrux refused to start the login (HTTP 400).');
});

describe('CompleteDeviceLogin', function (): void {
    it('polls until the user approved, then stores the tokens and dispatches LoggedIn', function (): void {
        Event::fake();
        Saloon::fake([pending(), tokenResponse()]);

        $tokens = resolve(CompleteDeviceLogin::class)->handle(pendingDevice());

        expect($tokens)->toBeInstanceOf(Tokens::class)
            ->and($tokens->accessToken)->toBe('access')
            ->and(resolve(TokenStore::class)->get()?->refreshToken)->toBe('refresh');

        Saloon::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
        Event::assertDispatchedTimes(LoggedIn::class, 1);
        Event::assertDispatched(LoggedIn::class, fn (LoggedIn $event): bool => $event->expiresAt->equalTo($tokens->expiresAt));
    });

    it('sends the device code and verifier on every poll', function (): void {
        Saloon::fake([tokenResponse()]);

        resolve(CompleteDeviceLogin::class)->handle(pendingDevice());

        Saloon::assertSent(fn (DeviceToken $request): bool => $request->body()->all() === [
            'client_id' => 'cirrux-cli',
            'device_code' => 'device-secret',
            'code_verifier' => 'verifier-secret',
        ]);
    });

    it('slows down by five seconds on every slow_down', function (): void {
        Saloon::fake([pending('slow_down'), pending('slow_down'), pending(), tokenResponse()]);

        resolve(CompleteDeviceLogin::class)->handle(pendingDevice());

        Sleep::assertSequence([
            Sleep::for(5)->seconds(),
            Sleep::for(10)->seconds(),
            Sleep::for(15)->seconds(),
            Sleep::for(15)->seconds(),
        ]);
        Saloon::assertSentCount(4);
    });

    it('gives up when the device code expires before approval', function (): void {
        Event::fake();
        Sleep::fake(syncWithCarbon: true);
        Saloon::fake([pending(), pending(), pending(), pending()]);

        try {
            resolve(CompleteDeviceLogin::class)->handle(pendingDevice(interval: 5, expiresIn: 12));
        } catch (CirruxAuthException $cirruxAuthException) {
            expect($cirruxAuthException->getMessage())->toBe('The device code expired before it was authorized.');
        }

        // Polled at +5s and +10s and +15s; the deadline (+12s) had passed after that.
        Saloon::assertSentCount(3);
        Event::assertNotDispatched(LoggedIn::class);
        expect(resolve(TokenStore::class)->get())->toBeNull();
    });

    it('fails when the user denied access', function (): void {
        Saloon::fake([pending('access_denied')]);

        resolve(CompleteDeviceLogin::class)->handle(pendingDevice());
    })->throws(CirruxAuthException::class, 'Authorization was denied.');

    it('fails when Cirrux reports the code as expired', function (): void {
        Saloon::fake([pending('expired_token')]);

        resolve(CompleteDeviceLogin::class)->handle(pendingDevice());
    })->throws(CirruxAuthException::class, 'The device code expired before it was authorized.');

    it('fails on an unknown error code', function (): void {
        Saloon::fake([pending('server_on_fire')]);

        resolve(CompleteDeviceLogin::class)->handle(pendingDevice());
    })->throws(CirruxAuthException::class, 'Login failed: server_on_fire.');

    it('fails on an error response without an error code', function (): void {
        Saloon::fake([MockResponse::make('Bad gateway', 502)]);

        resolve(CompleteDeviceLogin::class)->handle(pendingDevice());
    })->throws(CirruxAuthException::class, 'Login failed: HTTP 502.');

    it('reads the error code from a non 200 response too', function (): void {
        Saloon::fake([MockResponse::make(['error' => 'authorization_pending'], 400), tokenResponse()]);

        $tokens = resolve(CompleteDeviceLogin::class)->handle(pendingDevice());

        expect($tokens->accessToken)->toBe('access');
        Saloon::assertSentCount(2);
    });
});

describe('Logout', function (): void {
    it('clears the stored tokens and dispatches LoggedOut', function (): void {
        Event::fake();
        $store = resolve(TokenStore::class);
        $store->put(new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addHour()));

        resolve(Logout::class)->handle();

        expect($store->get())->toBeNull();
        Event::assertDispatched(LoggedOut::class);
    });
});
