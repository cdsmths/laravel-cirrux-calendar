<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Saloon\Config;

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Config::preventStrayRequests();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Feature', 'Unit');
