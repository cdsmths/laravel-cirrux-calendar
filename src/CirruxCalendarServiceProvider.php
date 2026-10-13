<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Codesmiths\CirruxCalendar\Actions\Auth\RefreshAccessToken;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Auth\DatabaseTokenStore;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Commands\CalendarsCommand;
use Codesmiths\CirruxCalendar\Commands\LoginCommand;
use Codesmiths\CirruxCalendar\Commands\LogoutCommand;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Saloon\RateLimitPlugin\Contracts\RateLimitStore;
use Saloon\RateLimitPlugin\Stores\LaravelCacheStore;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CirruxCalendarServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cirrux')
            ->hasConfigFile('cirrux')
            ->hasMigration('create_cirrux_tokens_table')
            ->hasCommands([
                CalendarsCommand::class,
                LoginCommand::class,
                LogoutCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Cirrux::class);
        $this->app->bind(TokenStore::class, DatabaseTokenStore::class);

        $this->app->when(RefreshAccessToken::class)
            ->needs(LockProvider::class)
            ->give(fn (): LockProvider => $this->lockProvider());

        $this->app->when(RefreshCalendar::class)
            ->needs(LockProvider::class)
            ->give(fn (): LockProvider => $this->lockProvider());

        $this->app->when(EventCache::class)
            ->needs(CacheRepository::class)
            ->give(fn (): CacheRepository => $this->cache());

        $this->app->when(CalendarList::class)
            ->needs(CacheRepository::class)
            ->give(fn (): CacheRepository => $this->cache());

        $this->app->when(CirruxConnector::class)
            ->needs(RateLimitStore::class)
            ->give(fn (): RateLimitStore => new LaravelCacheStore($this->cache()));
    }

    private function cache(): CacheRepository
    {
        $store = config('cirrux.cache.store');

        return Cache::store(is_string($store) ? $store : null);
    }

    private function lockProvider(): LockProvider
    {
        $cache = $this->cache();
        $store = $cache instanceof Repository ? $cache->getStore() : null;

        return $store instanceof LockProvider
            ? $store
            : throw new LogicException('The cirrux.cache.store cache store does not support atomic locks.');
    }
}
