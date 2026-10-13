<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Models\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a booking column in UTC whatever `app.timezone` is. Laravel's own datetime casts read a
 * zoneless database value in the app timezone, which moves every booking by the offset of an
 * app that does not run on UTC.
 *
 * The database holds `Y-m-d H:i:s` in UTC (whole seconds). A query that compares one of these
 * columns with a CarbonImmutable must bind it in UTC (`->utc()`): Laravel formats a bound date in
 * the zone of that object, not in UTC.
 *
 * @internal
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
    }

    /**
     * A string without an offset is taken to be UTC.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format(self::FORMAT);
        }

        return is_string($value) ? CarbonImmutable::parse($value, 'UTC')->utc()->format(self::FORMAT) : null;
    }
}
