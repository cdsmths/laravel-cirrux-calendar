<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar;

use Codesmiths\CirruxCalendar\Actions\Auth\RefreshAccessToken;
use Codesmiths\CirruxCalendar\Actions\Availability\ReportStaleCalendar;
use Codesmiths\CirruxCalendar\Actions\Cache\RefreshCalendar;
use Codesmiths\CirruxCalendar\Auth\DatabaseTokenStore;
use Codesmiths\CirruxCalendar\Auth\TokenStore;
use Codesmiths\CirruxCalendar\Calendars\CalendarList;
use Codesmiths\CirruxCalendar\Calendars\EventCache;
use Codesmiths\CirruxCalendar\Commands\CalendarsCommand;
use Codesmiths\CirruxCalendar\Commands\LoginCommand;
use Codesmiths\CirruxCalendar\Commands\LogoutCommand;
use Codesmiths\CirruxCalendar\Commands\RefreshCommand;
use Codesmiths\CirruxCalendar\Data\EventType;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
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
    private const int ONE_DAY_SECONDS = 86400;

    private const int ONE_DAY_MINUTES = 1440;

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
                RefreshCommand::class,
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

        $this->app->when(ReportStaleCalendar::class)
            ->needs(CacheRepository::class)
            ->give(fn (): CacheRepository => $this->cache());

        $this->app->when(CalendarList::class)
            ->needs(CacheRepository::class)
            ->give(fn (): CacheRepository => $this->cache());

        $this->app->when(CirruxConnector::class)
            ->needs(RateLimitStore::class)
            ->give(fn (): RateLimitStore => new LaravelCacheStore($this->cache()));
    }

    /**
     * Refuses a config that cannot work, so a mistake shows up when the app boots and not
     * as an empty booking page weeks later.
     *
     * @throws InvalidConfigurationException
     */
    public function packageBooted(): void
    {
        $weeksAhead = $this->integerAtLeast('cirrux.cache.weeks_ahead', 1);
        $maxStaleness = $this->integerAtLeast('cirrux.cache.max_staleness', 1);

        if ($maxStaleness > self::ONE_DAY_SECONDS) {
            throw InvalidConfigurationException::forKey('cirrux.cache.max_staleness', 'must be an integer of at most 86400 (one day)');
        }

        $this->integerAtLeast('cirrux.cache.ttl', $maxStaleness, 'cirrux.cache.max_staleness');
        $this->integerAtLeast('cirrux.cache.calendars_ttl', 1);
        $this->integerAtLeast('cirrux.lock.timeout', 0);
        $this->integerAtLeast('cirrux.rate_limit.per_minute', 1);

        $this->validateCacheStore();

        $eventTypes = config('cirrux.event_types');

        if (! is_array($eventTypes)) {
            throw InvalidConfigurationException::forKey('cirrux.event_types', 'must be an array');
        }

        foreach ($eventTypes as $name => $eventType) {
            $this->validateEventType((string) $name, $eventType, $weeksAhead);
        }
    }

    private function integerAtLeast(string $key, int $minimum, ?string $minimumKey = null): int
    {
        $value = config($key);

        if (! is_int($value) || $value < $minimum) {
            throw InvalidConfigurationException::forKey($key, sprintf('must be an integer of at least %s', $minimumKey ?? $minimum));
        }

        return $value;
    }

    /**
     * Locks between the web workers and the scheduler do nothing in a store that lives inside one process.
     */
    private function validateCacheStore(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        $configured = config('cirrux.cache.store');
        $store = is_string($configured) ? $configured : config('cache.default');
        $driver = is_string($store) ? config(sprintf('cache.stores.%s.driver', $store)) : null;

        if (in_array($driver, ['array', 'null'], true)) {
            throw InvalidConfigurationException::forKey('cirrux.cache.store', sprintf('uses the "%s" cache driver, which cannot share locks between processes', $driver));
        }
    }

    private function validateEventType(string $name, mixed $eventType, int $weeksAhead): void
    {
        $key = 'cirrux.event_types.'.$name;

        if (! is_array($eventType)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        $type = EventType::fromConfig($name, $eventType);

        // These limits keep every availability read inside the window of a refresh (see EventCache::refreshWindow()).
        if ($type->maxDaysAhead > ($weeksAhead - 1) * 7) {
            throw InvalidConfigurationException::forKey($key.'.max_days_ahead', 'must be an integer of at most (cirrux.cache.weeks_ahead - 1) * 7');
        }

        if ($type->bufferBefore > self::ONE_DAY_MINUTES) {
            throw InvalidConfigurationException::forKey($key.'.buffer_before', 'must be an integer of at most 1440 (one day)');
        }

        if ($type->duration + $type->bufferAfter > self::ONE_DAY_MINUTES) {
            throw InvalidConfigurationException::forKey($key.'.duration', 'plus buffer_after must be at most 1440 minutes (one day)');
        }
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
