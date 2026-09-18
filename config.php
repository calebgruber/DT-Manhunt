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
        'dsn' => getenv('DB_DSN') ?: 'sqlite:' . __DIR__ . '/manhunt.sqlite',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    'realtime' => [
        'ws_url' => 'wss://yourdomain.com/ws',
        'bridge_url' => 'http://127.0.0.1:3000/event',
        'bridge_secret' => getenv('BRIDGE_SECRET') ?: '',
    ],
    'security' => [
        'session_name' => 'dt_manhunt_session',
    ],
];
