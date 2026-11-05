<?php

return [
    // Deploy-time configuration only. Never selected by a request or Git branch.
    'mode' => env('APP_ARCHITECTURE', 'local'),
    'business_enabled' => (bool) env('BUSINESS_MICROSERVICES_ENABLED', false),
    'public_test_payments_enabled' => (bool) env('LIBELULA_REMOTE_TEST_PAYMENTS_ENABLED', false),
    'http' => [
        'connect_timeout' => (float) env('MICROSERVICE_CONNECT_TIMEOUT', 2),
        'timeout' => (float) env('MICROSERVICE_TIMEOUT', 5),
    ],
];
