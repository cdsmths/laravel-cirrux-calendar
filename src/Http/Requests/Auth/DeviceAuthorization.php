<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Auth;

use Codesmiths\CirruxCalendar\Data\DeviceCode;
use Illuminate\Support\Facades\Config;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/**
 * Starts the device flow. Cirrux wants a PKCE challenge here, like the CLI
 * sends, and the matching verifier when the device token is polled.
 */
final class DeviceAuthorization extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(#[SensitiveParameter] private readonly string $codeVerifier) {}

    public function resolveEndpoint(): string
    {
        return 'api/auth/device_authorization';
    }

    public function createDtoFromResponse(Response $response): DeviceCode
    {
        return DeviceCode::fromPayload($response->array(), $this->codeVerifier);
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'client_id' => Config::string('cirrux.client_id'),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
    }
}
