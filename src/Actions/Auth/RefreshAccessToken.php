<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Auth;

use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\CirruxFailure;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\TokenRefreshed;
use Codesmiths\CirruxCalendar\Events\TokenRefreshFailed;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Http\CirruxAuthConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\RefreshToken;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Config;
use Saloon\Exceptions\Request\FatalRequestException;

final readonly class RefreshAccessToken
{
    /**
     * Seconds before expiry from which an access token counts as (almost) expired.
     */
    public const int EXPIRY_MARGIN = 60;

    private const string LOCK = 'cirrux:token-refresh';

    /**
     * How long a crashed process can hold the lock before it is released on its own.
     */
    private const int LOCK_SECONDS = 30;

    /**
     * Statuses on which Cirrux will never accept this refresh token again.
     */
    private const array DEFINITIVE_STATUSES = [400, 401, 422];

    public function __construct(
        private TokenStore $tokens,
        private CirruxAuthConnector $connector,
        private LockProvider $locks,
    ) {}

    /**
     * Returns a usable token pair, refreshing it first when needed.
     *
     * Refresh tokens rotate, so two processes must never refresh with the same
     * one. The lock serialises them and the store is read again once the lock
     * is held: if another process refreshed in the meantime, its result is used.
     *
     * @param  string|null  $rejectedAccessToken  the access token Cirrux answered 401 to; when
     *                                            null the refresh only happens if the stored token expires soon
     *
     * @throws CirruxAuthException when the refresh failed for good and someone has to log in again
     * @throws CirruxRequestException when the refresh failed for a reason that may pass
     */
    public function handle(?string $rejectedAccessToken = null): Tokens
    {
        try {
            /** @var Tokens $tokens */
            $tokens = $this->locks
                ->lock(self::LOCK, self::LOCK_SECONDS)
                ->block(Config::integer('cirrux.lock.timeout'), fn (): Tokens => $this->refresh($rejectedAccessToken));
        } catch (LockTimeoutException $exception) {
            throw new CirruxRequestException('Timed out waiting for another process to refresh the Cirrux token.', 0, $exception);
        } catch (CirruxAuthException $exception) {
            event(new TokenRefreshFailed(CirruxFailure::from($exception)));

            throw $exception;
        }

        return $tokens;
    }

    private function refresh(?string $rejectedAccessToken): Tokens
    {
        $current = $this->tokens->get() ?? throw new CirruxAuthException('Not logged in to Cirrux.');

        if ($this->isStillUsable($current, $rejectedAccessToken)) {
            return $current;
        }

        try {
            $response = $this->connector->send(new RefreshToken($current->refreshToken));
        } catch (FatalRequestException $fatalRequestException) {
            throw new CirruxRequestException('Could not reach Cirrux to refresh the token.', 0, $fatalRequestException);
        }

        if (in_array($response->status(), self::DEFINITIVE_STATUSES, true)) {
            throw new CirruxAuthException(sprintf('Cirrux rejected the refresh token (HTTP %d).', $response->status()));
        }

        if ($response->failed()) {
            throw new CirruxRequestException(sprintf('Refreshing the Cirrux token failed (HTTP %d).', $response->status()), $response->status());
        }

        /** @var Tokens $tokens */
        $tokens = $response->dtoOrFail();

        // Persist before anything uses the new pair: the old refresh token is already dead.
        $this->tokens->put($tokens);
        event(new TokenRefreshed($tokens->expiresAt));

        return $tokens;
    }

    private function isStillUsable(Tokens $current, ?string $rejectedAccessToken): bool
    {
        if ($rejectedAccessToken === null) {
            return ! $current->expiresWithin(self::EXPIRY_MARGIN);
        }

        return $current->accessToken !== $rejectedAccessToken;
    }
}
