<?php

return [
    'microservice_sync_enabled' => env('MICROSERVICE_SYNC_ENABLED', false),
    'jwt_secret' => env('JWT_SECRET', ''),
    'libelula' => [
        'reconciliation_enabled' => env('LIBELULA_RECONCILIATION_ENABLED', false),
        'enabled' => env('LIBELULA_ENABLED', 1),
        'base_url' => env('LIBELULA_API_URL', 'https://api.libelula.bo'),
        'api_key' => env('LIBELULA_API_KEY'),
        'callback_base_url' => env('APP_URL', 'http://localhost:8000'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],
    'comercial' => ['base_url' => env('COMERCIAL_SERVICE_URL', 'http://localhost:5213')],
    'finanzas' => ['base_url' => env('FINANZAS_SERVICE_URL', 'http://localhost:5214')],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'propiedades' => [
        'base_url' => env('PROPIEDADES_SERVICE_URL', 'http://localhost:5212'),
    ],

    'seguridad' => [
        'base_url' => env('SEGURIDAD_SERVICE_URL', 'http://localhost:5211'),
        'sync_email' => env('PROPIEDADES_SYNC_EMAIL'),
        'sync_password' => env('PROPIEDADES_SYNC_PASSWORD'),
    ],

];
