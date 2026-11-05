<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\IsolatedDatabaseGuard;

// Explicitly scoped config:cache exercise; never writes bootstrap/cache or business data.
$root = realpath(__DIR__.'/../../storage/framework/testing');
$relative = getenv('APP_CONFIG_CACHE');
$path = $root.DIRECTORY_SEPARATOR.basename($relative);
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || getenv('DB_DATABASE') !== 'multilider_tests_isolated'
    || ! $root || ! in_array($relative, ['storage/framework/testing/live-local-config.php', 'storage/framework/testing/live-remote-config.php', 'storage/framework/testing/f5-remote-config.php', 'storage/framework/testing/f5-local-config.php'], true)) {
    throw new RuntimeException('Unsafe configuration fixture.');
}
if (is_file($path)) {
    unlink($path);
}
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $root.DIRECTORY_SEPARATOR.'app';
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'Private config cache failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath($root.DIRECTORY_SEPARATOR.'app');
$app->make(Kernel::class)->bootstrap();
IsolatedDatabaseGuard::check($app);
try {
    $result = Artisan::call('config:cache');
} catch (Throwable $error) {
    fwrite(STDERR, 'Private cache creation failed.'.PHP_EOL);
    exit(1);
}
if ($result !== 0 || ! is_file($path)) {
    fwrite(STDERR, 'Fixture configuration cache failed.'.PHP_EOL);
    exit(1);
}
$config = require $path;
if ($config['architecture']['mode'] !== getenv('APP_ARCHITECTURE')) {
    fwrite(STDERR, 'Cached architecture changed.'.PHP_EOL);
    exit(1);
}
echo 'Private configuration cache verified: '.getenv('APP_ARCHITECTURE').PHP_EOL;
