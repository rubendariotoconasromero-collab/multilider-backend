<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\IsolatedDatabaseGuard;

require_once __DIR__.'/IsolatedDatabaseGuard.php';
// Explicitly scoped config:cache exercise; never writes bootstrap/cache or business data.
$root = realpath(__DIR__.'/../../storage/framework/testing');
$relative = getenv('APP_CONFIG_CACHE');
$path = $root.DIRECTORY_SEPARATOR.basename($relative);
if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || ! IsolatedDatabaseGuard::allowedName((string) getenv('DB_DATABASE'))
    || ! $root || ! in_array($relative, ['storage/framework/testing/live-local-config.php', 'storage/framework/testing/live-remote-config.php',
        'storage/framework/testing/f5-remote-config.php', 'storage/framework/testing/f5-local-config.php',
        'storage/framework/testing/f5-restored-local-config.php', 'storage/framework/testing/f5-restored-remote-config.php'], true)) {
    throw new RuntimeException('Unsafe configuration fixture.');
}
if (is_file($path)) {
    unlink($path);
}
$storage = $root.DIRECTORY_SEPARATOR.'app';
if (IsolatedDatabaseGuard::allowedName((string) getenv('MULTILIDER_F5_RESTORED_MYSQL')) && getenv('DB_DATABASE') === getenv('MULTILIDER_F5_RESTORED_MYSQL')) {
    $storage = getenv('MULTILIDER_F5_RESTORED_STORAGE');
    if (! $storage || dirname(dirname($storage)) !== $root || ! preg_match('/^f5-restore-[a-f0-9]{32}$/D', basename(dirname($storage)))) {
        throw new RuntimeException('Unsafe restored storage path.');
    }
}
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'Private config cache failed: '.$error->getMessage().PHP_EOL);
    exit(1);
});
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath($storage);
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
