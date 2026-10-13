<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Data;

use Codesmiths\CirruxCalendar\Exceptions\InvalidConfigurationException;
use DateTimeZone;

/**
 * One entry of `cirrux.event_types`: what can be booked and when.
 *
 * Durations are in minutes, `maxDaysAhead` in days. `hours` holds wall-clock periods
 * in `timezone`; a day without a key cannot be booked.
 */
final readonly class EventType
{
    private const array DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun', list<array{string, string}>>  $hours
     */
    public function __construct(
        public string $name,
        public ?string $calendar,
        public string $title,
        public int $duration,
        public int $bufferBefore,
        public int $bufferAfter,
        public int $slotInterval,
        public int $minNotice,
        public int $maxDaysAhead,
        public string $timezone,
        public array $hours,
    ) {}

    /**
     * `max_days_ahead` against `cache.weeks_ahead` is not checked here: that is a rule between two
     * parts of the config, and the service provider owns it.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigurationException
     */
    public static function fromConfig(string $name, array $config): self
    {
        $key = 'cirrux.event_types.'.$name;

        $calendar = $config['calendar'] ?? null;

        if ($calendar !== null && ! is_string($calendar)) {
            throw InvalidConfigurationException::forKey($key.'.calendar', 'must be a string or null');
        }

        $title = $config['title'] ?? null;

        if (! is_string($title) || $title === '') {
            throw InvalidConfigurationException::forKey($key.'.title', 'must be a non-empty string');
        }

        $timezone = $config['timezone'] ?? null;

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidConfigurationException::forKey($key.'.timezone', 'must be a valid time zone identifier');
        }

        return new self(
            name: $name,
            calendar: $calendar,
            title: $title,
            duration: self::integerAtLeast($config, $key, 'duration', 1),
            bufferBefore: self::integerAtLeast($config, $key, 'buffer_before', 0),
            bufferAfter: self::integerAtLeast($config, $key, 'buffer_after', 0),
            slotInterval: self::integerAtLeast($config, $key, 'slot_interval', 1),
            minNotice: self::integerAtLeast($config, $key, 'min_notice', 0),
            maxDaysAhead: self::integerAtLeast($config, $key, 'max_days_ahead', 1),
            timezone: $timezone,
            hours: self::hours($key.'.hours', $config['hours'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function integerAtLeast(array $config, string $key, string $field, int $minimum): int
    {
        $value = $config[$field] ?? null;

        if (! is_int($value) || $value < $minimum) {
            throw InvalidConfigurationException::forKey($key.'.'.$field, sprintf('must be an integer of at least %d', $minimum));
        }

        return $value;
    }

    /**
     * @return array<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun', list<array{string, string}>>
     */
    private static function hours(string $key, mixed $hours): array
    {
        if (! is_array($hours)) {
            throw InvalidConfigurationException::forKey($key, 'must be an array');
        }

        $parsed = [];

        foreach ($hours as $day => $periods) {
            if (! in_array($day, self::DAYS, true)) {
                throw InvalidConfigurationException::forKey($key, sprintf('has the day "%s", expected mon, tue, wed, thu, fri, sat or sun', $day));
            }

            if (! is_array($periods) || ! array_is_list($periods)) {
                throw InvalidConfigurationException::forKey($key.'.'.$day, 'must be a list of ["HH:MM", "HH:MM"] periods');
            }

            $parsed[$day] = array_map(
                static fn (mixed $period): array => self::period($period) ?? throw InvalidConfigurationException::forKey($key.'.'.$day, 'must only hold ["HH:MM", "HH:MM"] periods that end after they start'),
                $periods,
            );
        }

        return $parsed;
    }

    /**
     * @return array{string, string}|null
     */
    private static function period(mixed $period): ?array
    {
        if (! is_array($period) || ! array_is_list($period) || count($period) !== 2) {
            return null;
        }

        [$start, $end] = $period;

        // Both are zero padded, so comparing the strings compares the times.
        if (! self::isClockTime($start) || ! self::isClockTime($end) || $start >= $end) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * @phpstan-assert-if-true string $time
     */
    private static function isClockTime(mixed $time): bool
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
