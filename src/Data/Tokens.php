<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

final readonly class Tokens
{
    /**
     * Assumed lifetime when Cirrux does not say how long an access token is valid.
     * Deliberately short: an early refresh is cheap, an expired token is not.
     */
    private const int FALLBACK_LIFETIME = 300;

    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload  a token response from the Cirrux auth API
     * @param  string|null  $currentRefreshToken  kept when the response does not rotate the refresh token
     */
    public static function fromPayload(array $payload, ?string $currentRefreshToken = null): self
    {
        return new self(
            accessToken: Arr::string($payload, 'access_token'),
            refreshToken: Arr::string($payload, 'refresh_token', $currentRefreshToken),
            expiresAt: CarbonImmutable::now('UTC')->addSeconds(
                Arr::integer($payload, 'access_token_expires_in', self::FALLBACK_LIFETIME),
            ),
        );
    }

    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC')->addSeconds($seconds));
    }
}
