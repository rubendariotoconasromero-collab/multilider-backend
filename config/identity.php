<?php

return [
    'issuer' => env('IDENTITY_ISSUER', 'MSMultilider.Seguridad'),
    'audience' => env('IDENTITY_AUDIENCE', 'MSMultilider.Frontend'),
    'key' => env('IDENTITY_SIGNING_KEY', env('JWT_SECRET', '')),
    'clock_skew' => 30,
    'legacy_until' => env('IDENTITY_LEGACY_UNTIL'),
];
