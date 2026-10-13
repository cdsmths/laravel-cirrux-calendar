<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Auth;

use Carbon\CarbonImmutable;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Data\DeviceCode;
use Codesmiths\CirruxCalendar\Data\Tokens;
use Codesmiths\CirruxCalendar\Events\LoggedIn;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Http\CirruxAuthConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceToken;
use Illuminate\Support\Sleep;
use JsonException;
use Saloon\Http\Response;

final readonly class CompleteDeviceLogin
{
    /**
     * RFC 8628 section 3.5: back off by five seconds on every slow_down.
     */
    private const int SLOW_DOWN_STEP = 5;

    public function __construct(
        private CirruxAuthConnector $connector,
        private TokenStore $tokens,
    ) {}

    /**
     * Polls until the user approved the device code, then stores the tokens.
     *
     * @throws CirruxAuthException when the login is denied, fails or the code expires
     */
    public function handle(DeviceCode $device): Tokens
    {
        $deadline = CarbonImmutable::now('UTC')->addSeconds($device->expiresIn);
        $interval = $device->interval;

        while (CarbonImmutable::now('UTC')->lessThan($deadline)) {
            Sleep::for($interval)->seconds();

            $response = $this->connector->send(new DeviceToken($device));
            $error = $this->errorCode($response);

            if ($error === null && $response->successful()) {
                $tokens = $response->dtoOrFail();
                assert($tokens instanceof Tokens);

                $this->tokens->put($tokens);
                event(new LoggedIn($tokens->expiresAt));

                return $tokens;
            }

            $interval = match ($error) {
                'authorization_pending' => $interval,
                'slow_down' => $interval + self::SLOW_DOWN_STEP,
                'access_denied' => throw new CirruxAuthException('Authorization was denied.'),
                'expired_token' => throw new CirruxAuthException('The device code expired before it was authorized.'),
                default => throw new CirruxAuthException(sprintf(
                    'Login failed: %s.',
                    $error ?? 'HTTP '.$response->status(),
                )),
            };
        }

        throw new CirruxAuthException('The device code expired before it was authorized.');
    }

    /**
     * Cirrux puts an RFC 8628 error code in the body; a gateway error page has none.
     */
    private function errorCode(Response $response): ?string
    {
        try {
            $error = $response->json('error');
        } catch (JsonException) {
            return null;
        }

        return is_string($error) ? $error : null;
    }
}
