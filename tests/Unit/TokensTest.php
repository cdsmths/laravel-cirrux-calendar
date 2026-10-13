<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Tokens;

it('builds tokens from an auth response', function (): void {
    $tokens = Tokens::fromPayload([
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'access_token_expires_in' => 3600,
    ]);

    expect($tokens->accessToken)->toBe('access')
        ->and($tokens->refreshToken)->toBe('refresh')
        ->and($tokens->expiresAt->equalTo(CarbonImmutable::now('UTC')->addHour()))->toBeTrue()
        ->and($tokens->expiresAt->getTimezone()->getName())->toBe('UTC');
});

it('keeps the current refresh token when the response does not rotate it', function (): void {
    $tokens = Tokens::fromPayload(['access_token' => 'new-access'], currentRefreshToken: 'old-refresh');

    expect($tokens->refreshToken)->toBe('old-refresh');
});

it('assumes a short lifetime when the response gives none', function (): void {
    $tokens = Tokens::fromPayload(['access_token' => 'access'], currentRefreshToken: 'refresh');

    expect($tokens->expiresAt->equalTo(CarbonImmutable::now('UTC')->addMinutes(5)))->toBeTrue();
});

it('rejects a response without an access token', function (): void {
    Tokens::fromPayload(['refresh_token' => 'refresh']);
})->throws(InvalidArgumentException::class);

it('knows when it expires within a margin', function (): void {
    $tokens = new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->addSeconds(90));

    expect($tokens->expiresWithin(60))->toBeFalse()
        ->and($tokens->expiresWithin(90))->toBeTrue()
        ->and($tokens->expiresWithin(120))->toBeTrue();
});

it('is expired when the expiry is in the past', function (): void {
    $tokens = new Tokens('access', 'refresh', CarbonImmutable::now('UTC')->subSecond());

    expect($tokens->expiresWithin(0))->toBeTrue();
});
