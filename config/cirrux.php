<?php

declare(strict_types=1);

return [
    'base_url' => env('CIRRUX_BASE_URL', 'https://api.cirrux.co/'),
    'client_id' => env('CIRRUX_CLIENT_ID', 'cirrux-cli'),    // OAuth client of the device flow

    'cache' => [
        'store' => env('CIRRUX_CACHE_STORE'),                // must support locks and be shared
        'ttl' => 86400,                                       // seconds, last known good
        'max_staleness' => 7200,                              // seconds; older data gives no slots
        'weeks_ahead' => 4,                                   // weeks a refresh fetches ahead
        'calendars_ttl' => 86400,                             // seconds
    ],

    'lock' => [
        'timeout' => 10,                                      // seconds to wait for a calendar lock
    ],

    'rate_limit' => [
        'per_minute' => 60,                                   // requests; Cirrux allows 600
    ],

    'event_types' => [
        'demo' => [
            'calendar' => env('CIRRUX_DEMO_CALENDAR'),        // uuid, see `php artisan cirrux:calendars`
            'title' => 'Demo',
            'url' => env('CIRRUX_DEMO_URL'),                  // optional link on every booking, e.g. a video call
            'location' => null,                               // optional location on every booking
            'duration' => 30,                                 // minutes
            'buffer_before' => 10,                            // minutes free before a slot, around every busy event
            'buffer_after' => 10,                             // minutes free after a slot
            'slot_interval' => 30,                            // minutes between slot starts
            'min_notice' => 60 * 24,                          // minutes
            'max_days_ahead' => 21,                           // days, at most (weeks_ahead - 1) * 7
            'timezone' => 'Europe/Amsterdam',                 // the working hours are in this zone
            'hours' => [                                      // days left out cannot be booked
                'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
                'tue' => [['09:00', '12:00'], ['13:00', '17:00']],
                'wed' => [['09:00', '12:00'], ['13:00', '17:00']],
                'thu' => [['09:00', '12:00'], ['13:00', '17:00']],
                'fri' => [['09:00', '12:00'], ['13:00', '17:00']],
            ],
        ],
    ],
];
