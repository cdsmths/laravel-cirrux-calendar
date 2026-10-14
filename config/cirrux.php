<?php

declare(strict_types=1);

return [
    'base_url' => env('CIRRUX_BASE_URL', 'https://api.cirrux.co/'),
    'client_id' => env('CIRRUX_CLIENT_ID', 'cirrux-cli'),

    'cache' => [
        'store' => env('CIRRUX_CACHE_STORE'),   // moet locks ondersteunen en gedeeld zijn
        'ttl' => 86400,                          // last known good
        'max_staleness' => 7200,                 // daarboven: geen slots
        'weeks_ahead' => 4,
        'calendars_ttl' => 86400,
    ],

    'lock' => [
        'timeout' => 10,                         // seconden wachten op de agenda-lock
    ],

    'rate_limit' => [
        'per_minute' => 60,
    ],

    'event_types' => [
        'demo' => [
            'calendar' => env('CIRRUX_DEMO_CALENDAR'),
            'title' => 'Demo',
            // Op elk event van een boeking; een boeking kan eigen waarden meegeven.
            'url' => env('CIRRUX_DEMO_URL'),     // bijv. de link van een vaste videokamer
            'location' => null,
            'duration' => 30,                 // minuten
            'buffer_before' => 10,            // rond alle opaque events
            'buffer_after' => 10,
            'slot_interval' => 30,
            'min_notice' => 60 * 24,          // minuten
            'max_days_ahead' => 21,           // moet <= (weeks_ahead - 1) * 7
            'timezone' => 'Europe/Amsterdam',
            // Eerste use case: demo's alleen op maandag en vrijdag.
            // Dagen die ontbreken zijn niet boekbaar.
            'hours' => [
                'mon' => [['09:00', '12:00'], ['13:00', '17:00']],
                'fri' => [['09:00', '12:00'], ['13:00', '17:00']],
            ],
        ],
    ],
];
