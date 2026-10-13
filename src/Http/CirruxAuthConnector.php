<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Http;

use Illuminate\Support\Facades\Config;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * The api/auth/* endpoints. Not a standard OAuth2 server (JSON bodies, its own
 * device flow), so these are plain requests instead of Saloon's OAuth2 traits.
 */
final class CirruxAuthConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        return Config::string('cirrux.base_url');
    }
}
