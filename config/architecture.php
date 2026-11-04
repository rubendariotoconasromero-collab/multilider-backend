<?php

return [
    // Deploy-time configuration only. Never selected by a request or Git branch.
    'mode' => env('APP_ARCHITECTURE', 'local'),
    'http' => [
        'connect_timeout' => (float) env('MICROSERVICE_CONNECT_TIMEOUT', 2),
        'timeout' => (float) env('MICROSERVICE_TIMEOUT', 5),
    ],
];
