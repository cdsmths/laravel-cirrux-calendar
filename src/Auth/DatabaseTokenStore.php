<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Auth;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;

/**
 * Keeps the single token pair in the database, encrypted at rest. Never in the
 * cache: refresh tokens rotate, so a cache flush would log the package out for good.
 */
final readonly class DatabaseTokenStore implements TokenStore
{
    private const string TABLE = 'cirrux_tokens';

    private const int ROW_ID = 1;

    public function __construct(
        private ConnectionInterface $connection,
        private StringEncrypter $encrypter,
    ) {}

    public function get(): ?Tokens
    {
        $row = $this->connection->table(self::TABLE)->where('id', self::ROW_ID)->first();

        if ($row === null) {
            return null;
        }

        $columns = (array) $row;

        return new Tokens(
            accessToken: $this->encrypter->decryptString(Arr::string($columns, 'access_token')),
            refreshToken: $this->encrypter->decryptString(Arr::string($columns, 'refresh_token')),
            expiresAt: new CarbonImmutable(Arr::string($columns, 'access_token_expires_at'), 'UTC'),
        );
    }

    public function put(Tokens $tokens): void
    {
        $now = CarbonImmutable::now('UTC')->toDateTimeString();

        // A single upsert statement, so a reader never sees a half-written pair.
        $this->connection->table(self::TABLE)->upsert(
            [[
                'id' => self::ROW_ID,
                'access_token' => $this->encrypter->encryptString($tokens->accessToken),
                'refresh_token' => $this->encrypter->encryptString($tokens->refreshToken),
                'access_token_expires_at' => $tokens->expiresAt->utc()->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['id'],
            ['access_token', 'refresh_token', 'access_token_expires_at', 'updated_at'],
        );
    }

    public function forget(): void
    {
        $this->connection->table(self::TABLE)->delete();
    }
}
