<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http\Requests\Auth;

use Codesmiths\CirruxCalendar\Data\DeviceCode;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Illuminate\Support\Facades\Config;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Polls for the device flow result. Cirrux answers 200 with either the token
 * bundle or an RFC 8628 `error` code (authorization_pending, slow_down, ...).
 */
final class DeviceToken extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly DeviceCode $device) {}

    public function resolveEndpoint(): string
    {
        return 'api/auth/device_token';
    }

    public function createDtoFromResponse(Response $response): Tokens
    {
        return Tokens::fromPayload($response->array());
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'client_id' => Config::string('cirrux.client_id'),
            'device_code' => $this->device->deviceCode,
            'code_verifier' => $this->device->codeVerifier,
        ];
    }
}
