<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\DatabaseTokenStore;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\DB;

function tokens(string $suffix = '', int $expiresInSeconds = 3600): Tokens
{
    return new Tokens(
        accessToken: 'access-token'.$suffix,
        refreshToken: 'refresh-token'.$suffix,
        expiresAt: CarbonImmutable::now('UTC')->addSeconds($expiresInSeconds),
    );
}

it('binds the database store as the token store', function (): void {
    expect(resolve(TokenStore::class))->toBeInstanceOf(DatabaseTokenStore::class);
});

it('has no tokens before login', function (): void {
    expect(resolve(TokenStore::class)->get())->toBeNull();
});

it('round-trips a token pair', function (): void {
    $store = resolve(TokenStore::class);
    $tokens = tokens();

    $store->put($tokens);
    $stored = $store->get();

    expect($stored)->toBeInstanceOf(Tokens::class)
        ->and($stored->accessToken)->toBe('access-token')
        ->and($stored->refreshToken)->toBe('refresh-token')
        ->and($stored->expiresAt->getTimestamp())->toBe($tokens->expiresAt->getTimestamp());
});

it('encrypts both tokens at rest', function (): void {
    resolve(TokenStore::class)->put(tokens());

    $row = DB::table('cirrux_tokens')->first();

    expect($row->access_token)->not->toContain('access-token')
        ->and($row->refresh_token)->not->toContain('refresh-token')
        ->and(resolve(StringEncrypter::class)->decryptString($row->access_token))->toBe('access-token')
        ->and(resolve(StringEncrypter::class)->decryptString($row->refresh_token))->toBe('refresh-token');
});

it('keeps a single row when the pair is replaced', function (): void {
    $store = resolve(TokenStore::class);

    $store->put(tokens());

    $createdAt = DB::table('cirrux_tokens')->value('created_at');
    $store->put(tokens('-2'));

    expect(DB::table('cirrux_tokens')->count())->toBe(1)
        ->and(DB::table('cirrux_tokens')->value('created_at'))->toBe($createdAt)
        ->and($store->get()?->refreshToken)->toBe('refresh-token-2');
});

it('forgets the tokens', function (): void {
    $store = resolve(TokenStore::class);
    $store->put(tokens());

    $store->forget();

    expect($store->get())->toBeNull()
        ->and(DB::table('cirrux_tokens')->count())->toBe(0);
});
