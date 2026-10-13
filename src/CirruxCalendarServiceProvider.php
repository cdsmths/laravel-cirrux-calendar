<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CirruxCalendarServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cirrux')
            ->hasConfigFile('cirrux');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Cirrux::class);
    }
}
