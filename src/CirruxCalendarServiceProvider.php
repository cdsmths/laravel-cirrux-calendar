<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Codesmiths\CirruxCalendar\Auth\DatabaseTokenStore;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CirruxCalendarServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cirrux')
            ->hasConfigFile('cirrux')
            ->hasMigration('create_cirrux_tokens_table');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Cirrux::class);
        $this->app->bind(TokenStore::class, DatabaseTokenStore::class);
    }
}
