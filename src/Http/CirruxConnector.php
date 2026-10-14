<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http;

use Codesmiths\CirruxCalendar\Actions\Auth\RefreshAccessToken;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Exceptions\CirruxRequestException;
use Codesmiths\CirruxCalendar\Exceptions\RateLimitedException;
use Codesmiths\CirruxCalendar\Http\Requests\Events\EventsPaginator;
use Illuminate\Support\Facades\Config;
use Saloon\Contracts\Authenticator;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;
use Saloon\RateLimitPlugin\Limit;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AcceptsJson;
use Throwable;

/**
 * The Cirrux public API. Failures leave this class as Cirrux exceptions.
 */
final class CirruxConnector extends Connector implements HasPagination
{
    use AcceptsJson;
    use HasRateLimits;

    /**
     * One retry, and only for a 401 (see handleRetry()).
     */
    public ?int $tries = 2;

    public function __construct(
        private readonly TokenStore $tokens,
        private readonly RefreshAccessToken $refreshAccessToken,
        private readonly RateLimitStore $limitStore,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim(Config::string('cirrux.base_url'), '/').'/public_api/v1';
    }

    public function paginate(Request $request): EventsPaginator
    {
        return new EventsPaginator($this, $request);
    }

    /**
     * A 401 means the access token was revoked or expired early: refresh it once
     * and try again. Every other failure is final, so a POST that did arrive is
     * never sent twice.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
    {
        if (! $exception instanceof RequestException || $exception->getStatus() !== 401) {
            return false;
        }

        $rejected = $exception->getPendingRequest()->getAuthenticator();

        // The retry builds a new pending request, so defaultAuth() reads the refreshed pair.
        $this->refreshAccessToken->handle($rejected instanceof TokenAuthenticator ? $rejected->token : '');

        return true;
    }

    /**
     * @param  callable(Throwable, Request):bool|null  $handleRetry
     *
     * @throws RateLimitedException when Cirrux or the local limiter says to slow down
     * @throws CirruxRequestException when the request fails
     */
    public function send(Request $request, ?MockClient $mockClient = null, ?callable $handleRetry = null): Response
    {
        try {
            return parent::send($request, $mockClient, $handleRetry)->throw();
        } catch (RateLimitReachedException $exception) {
            throw new RateLimitedException($exception->getLimit()->getRemainingSeconds(), $exception);
        } catch (RequestException $exception) {
            throw $this->requestException($exception);
        } catch (FatalRequestException $exception) {
            throw new CirruxRequestException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Read on every attempt, never kept in a property: a retry after a refresh
     * must send the new token.
     */
    protected function defaultAuth(): Authenticator
    {
        $tokens = $this->tokens->get();

        if (! $tokens instanceof Tokens || $tokens->expiresWithin(RefreshAccessToken::EXPIRY_MARGIN)) {
            $tokens = $this->refreshAccessToken->handle();
        }

        return new TokenAuthenticator($tokens->accessToken);
    }

    /**
     * @return list<Limit>
     */
    protected function resolveLimits(): array
    {
        return [Limit::allow(Config::integer('cirrux.rate_limit.per_minute'))->everyMinute()];
    }

    protected function resolveRateLimitStore(): RateLimitStore
    {
        return $this->limitStore;
    }

    /**
     * Cirrux answers a failure with `{"error": "<code>", "error_description": "<text>"}`
     * (cirrux.co/help/api). With that body the message names the code and the description;
     * without it, Saloon's message is kept.
     */
    private function requestException(RequestException $exception): CirruxRequestException
    {
        $status = $exception->getStatus();
        $body = json_decode($exception->getResponse()->body(), true);
        $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;

        if ($error === null) {
            return new CirruxRequestException($exception->getMessage(), $status, $exception);
        }

        $description = is_string($body['error_description'] ?? null) ? $body['error_description'] : null;
        $message = sprintf('Cirrux answered %d %s', $status, $error).($description !== null ? ': '.$description : '.');

        return new CirruxRequestException($message, $status, $exception, $error);
    }
}
