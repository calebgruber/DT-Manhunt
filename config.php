<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'DT Manhunt',
        'organization' => 'SUNY Purchase',
        'timezone' => 'UTC',
        'environment' => 'production',
    ],
   'database' => [
        // Fill these with your production DB details.
        // Current runtime supports SQLite (default) and MySQL.
        'dsn' => getenv('voxelnodes_manhunt') ?: 'sqlite:' . __DIR__ . '/manhunt.sqlite',
        'user' => getenv('voxelnodes_manhunt') ?: '',
        'pass' => getenv('@@-(GkElsB2,o+yF') ?: '',
    ],
    'realtime' => [
        'ws_url' => 'wss://calebgruber.me/ws',
        'bridge_url' => 'http://127.0.0.1:3000/event',
        'bridge_secret' => getenv('BRIDGE_SECRET') ?: '',
    ],
    'security' => [
        'session_name' => 'dt_manhunt_session',
    ],
    'registration' => [
        // Admin-editable dropdown values.
        'graduation_year_options' => ['2026', '2027', '2028', '2029', '2030'],
        'concentration_options' => [
            'Stage Management',
            'Lighting Design',
            'Sound Design',
            'Scenic',
            'TD / PM',
        ],
    ],
];
