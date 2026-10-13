<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Auth;

use Codesmiths\CirruxCalendar\Data\Tokens;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/**
 * Trades a refresh token for a new pair. Refresh tokens rotate, so the old one
 * is useless after a successful call.
 */
final class RefreshToken extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(#[SensitiveParameter] private readonly string $refreshToken) {}

    public function resolveEndpoint(): string
    {
        return 'api/auth/refresh_token';
    }

    public function createDtoFromResponse(Response $response): Tokens
    {
        return Tokens::fromPayload($response->array(), $this->refreshToken);
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return ['refresh_token' => $this->refreshToken];
    }
}
