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
use Codesmiths\CirruxCalendar\Commands\RefreshCommand;
use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use DateTimeZone;
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
            $this->validateEventType('cirrux.event_types.'.$name, $eventType, $weeksAhead);
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

    private function validateEventType(string $key, mixed $eventType, int $weeksAhead): void
    {
        if (! is_array($eventType)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        $timezone = $eventType['timezone'] ?? null;

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidConfigurationException::forKey($key.'.timezone', 'must be a valid time zone identifier');
        }

        $maxDaysAhead = $eventType['max_days_ahead'] ?? null;

        if (! is_int($maxDaysAhead) || $maxDaysAhead > $weeksAhead * 7) {
            throw InvalidConfigurationException::forKey($key.'.max_days_ahead', 'must be an integer of at most cirrux.cache.weeks_ahead * 7');
        }

        $this->validateHours($key.'.hours', $eventType['hours'] ?? null);
    }

    private function validateHours(string $key, mixed $hours): void
    {
        if (! is_array($hours)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        foreach ($hours as $day => $periods) {
            if (! in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true)) {
                throw InvalidConfigurationException::forKey($key, sprintf('has the day "%s", expected mon, tue, wed, thu, fri, sat or sun', $day));
            }

            if (! is_array($periods) || ! array_is_list($periods)) {
                throw InvalidConfigurationException::forKey($key.'.'.$day, 'must be a list of ["HH:MM", "HH:MM"] periods');
            }

            foreach ($periods as $period) {
                if (! $this->isValidPeriod($period)) {
                    throw InvalidConfigurationException::forKey($key.'.'.$day, 'must only hold ["HH:MM", "HH:MM"] periods that end after they start');
                }
            }
        }
    }

    private function isValidPeriod(mixed $period): bool
    {
        if (! is_array($period) || ! array_is_list($period) || count($period) !== 2) {
            return false;
        }

        [$start, $end] = $period;

        // Both are zero padded, so comparing the strings compares the times.
        return $this->isClockTime($start) && $this->isClockTime($end) && $start < $end;
    }

    private function isClockTime(mixed $time): bool
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
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
