<?php

// Mirrors the production storage symlink using only the isolated fixture root.
// Never expose the developer's real public/storage through the test HTTP servers.
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || getenv('DB_DATABASE') !== 'multilider_tests_isolated' || getenv('DB_PORT') === '3306') {
    http_response_code(503);

    return;
}
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = realpath(__DIR__.'/../../storage/framework/testing/app');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_starts_with($uri, '/storage/')) {
    if (! preg_match('~^/storage/propiedades/[1-9][0-9]*/f4-[a-f0-9-]{36}\.(jpg|jpeg|png|gif)$~D', $uri)) {
        http_response_code(404);

        return;
    }
    $root = realpath(__DIR__.'/../../storage/framework/testing/app/app/public');
    $path = realpath(__DIR__.'/../../storage/framework/testing/app/app/public/'.substr($uri, 9));
    if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
        http_response_code(404);

        return;
    }
    header('Content-Type: '.mime_content_type($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);

    return;
}
require __DIR__.'/../../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
