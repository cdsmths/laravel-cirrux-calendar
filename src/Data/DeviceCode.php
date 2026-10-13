<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * The result of starting a device login. Show the user code and verification
 * URI to the user; the device code and PKCE verifier stay with the package.
 */
final readonly class DeviceCode
{
    public function __construct(
        #[SensitiveParameter]
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public int $interval,
        public int $expiresIn,
        #[SensitiveParameter]
        public string $codeVerifier,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload  a device_authorization response from Cirrux
     */
    public static function fromPayload(array $payload, string $codeVerifier): self
    {
        return new self(
            deviceCode: Arr::string($payload, 'device_code'),
            userCode: Arr::string($payload, 'user_code'),
            verificationUri: Arr::string($payload, 'verification_uri'),
            interval: Arr::integer($payload, 'interval'),
            expiresIn: Arr::integer($payload, 'expires_in'),
            codeVerifier: $codeVerifier,
        );
    }
}
