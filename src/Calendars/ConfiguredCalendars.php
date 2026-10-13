<?php

declare(strict_types=1);

namespace Codesmiths\CirruxCalendar\Calendars;

use Illuminate\Support\Facades\Config;

/**
 * The calendars the app has set up: every distinct, filled-in `calendar` of `cirrux.event_types`.
 */
final readonly class ConfiguredCalendars
{
    /**
     * @return list<string>
     */
    public function uuids(): array
    {
        $uuids = [];

        foreach (Config::array('cirrux.event_types') as $eventType) {
            $calendar = is_array($eventType) ? ($eventType['calendar'] ?? null) : null;

            if (is_string($calendar) && $calendar !== '') {
                $uuids[] = $calendar;
            }
        }

        return array_values(array_unique($uuids));
    }
}
