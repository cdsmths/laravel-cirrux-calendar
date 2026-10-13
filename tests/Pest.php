<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Config::preventStrayRequests();
        Sleep::fake();
        MockClient::destroyGlobal();

        $this->freezeTime();
    })
    ->in('Feature', 'Unit');
