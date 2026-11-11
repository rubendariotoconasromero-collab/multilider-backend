<?php

use Tests\Support\IsolatedDatabaseGuard;

require_once __DIR__.'/IsolatedDatabaseGuard.php';

// Mirrors the production storage symlink using only the isolated fixture root.
// Never expose the developer's real public/storage through the test HTTP servers.
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || ! IsolatedDatabaseGuard::allowedName((string) getenv('DB_DATABASE')) || getenv('DB_PORT') === '3306') {
    http_response_code(503);

    return;
}
$storage = realpath(__DIR__.'/../../storage/framework/testing/app');
if (getenv('DB_DATABASE') === getenv('MULTILIDER_F5_RESTORED_MYSQL')) {
    $storage = realpath(getenv('MULTILIDER_F5_RESTORED_STORAGE'));
    $fixtureRoot = realpath(__DIR__.'/../../storage/framework/testing');
    if (! $storage || dirname(dirname($storage)) !== $fixtureRoot || ! preg_match('/^f5-restore-[a-f0-9]{32}$/D', basename(dirname($storage)))) {
        http_response_code(503);
        return;
    }
}
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_starts_with($uri, '/storage/')) {
    if (! preg_match('~^/storage/propiedades/[1-9][0-9]*/f4-[a-f0-9-]{36}\.(jpg|jpeg|png|gif)$~D', $uri)) {
        http_response_code(404);

        return;
    }
    $root = realpath($storage.'/app/public');
    $path = realpath($storage.'/app/public/'.substr($uri, 9));
    if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
        http_response_code(404);

        return;
    }
    header('Content-Type: '.mime_content_type($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);

    return;
}
if (getenv('APP_ARCHITECTURE') === 'local'
    && preg_match('~^/api/public/pagos/(procesar|callback|confirmar-retorno|estado/[^/]+|verificar/[^/]+)$~D', $uri)
    && ($boundary = realpath(getenv('MULTILIDER_F5_BOUNDARY_DIR') ?: '.'))
    && dirname($boundary) === realpath(__DIR__.'/../../storage/framework/testing')
    && preg_match('/^f5-boundary-[a-f0-9]{32}$/D', basename($boundary))) {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake([
        'payments.example.test/rest/deuda/registrar' => \Illuminate\Support\Facades\Http::response([
            'error' => 0, 'id_transaccion' => 'fixture', 'url_pasarela_pagos' => 'https://payments.example.test/checkout',
        ]),
        'payments.example.test/rest/deuda/consultar_deudas/por_fechas' => function () use ($boundary) {
            $reference = request()->input('identificador') ?? request()->input('id_transaccion') ?? basename(request()->path());
            $rows = [];
            if (preg_match('/^TEMP-([a-f0-9-]{36})$/D', $reference, $matches)) {
                $id = str_replace('-', '', $matches[1]);
                $marker = "$boundary/$id-Callback-after";
                if (is_file($marker.'.arm')) {
                    rename($marker.'.arm', $marker.'.claimed');
                    file_put_contents($marker.'.hit', getmypid());
                    $deadline = microtime(true) + 15;
                    while (! is_file($marker.'.release') && microtime(true) < $deadline) {
                        clearstatcache();
                        usleep(20000);
                    }
                }
                $rows[] = ['identificador' => $reference, 'pagado' => is_file("$boundary/$id-provider-paid"),
                    'valor_total' => '0.20', 'moneda' => 'BOB'];
            }

            return \Illuminate\Support\Facades\Http::response(['error' => 0, 'datos' => $rows]);
        },
    ]);
    $request = \Illuminate\Http\Request::capture();
    $app->instance('request', $request);
    $response = $kernel->handle($request)->send();
    $kernel->terminate($request, $response);
    return;
}
require __DIR__.'/../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
