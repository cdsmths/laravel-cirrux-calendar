<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Actions\Auth;

use Codesmiths\CirruxCalendar\Data\DeviceCode;
use Codesmiths\CirruxCalendar\Exceptions\CirruxAuthException;
use Codesmiths\CirruxCalendar\Http\CirruxAuthConnector;
use Codesmiths\CirruxCalendar\Http\Requests\Auth\DeviceAuthorization;
use Illuminate\Support\Str;

final readonly class StartDeviceLogin
{
    public function __construct(private CirruxAuthConnector $connector) {}

    public function handle(): DeviceCode
    {
        $response = $this->connector->send(new DeviceAuthorization(Str::random(64)));

        if ($response->failed()) {
            throw new CirruxAuthException(sprintf('Cirrux refused to start the login (HTTP %d).', $response->status()));
        }

        /** @var DeviceCode $deviceCode */
        $deviceCode = $response->dtoOrFail();

        return $deviceCode;
    }
}
