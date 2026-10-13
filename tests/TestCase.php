<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Tests;

use Codesmiths\CirruxCalendar\CirruxCalendarServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Saloon\Laravel\SaloonServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SaloonServiceProvider::class,
            CirruxCalendarServiceProvider::class,
        ];
    }
}
