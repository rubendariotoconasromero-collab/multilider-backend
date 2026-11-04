<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\IsolatedDatabaseGuard;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if (getenv('MULTILIDER_TEST_ISOLATED') !== '1') {
            throw new \RuntimeException('Pruebas bloqueadas: ejecutar scripts/test-isolated.ps1; nunca usar la base de .env.');
        }
        $storage = __DIR__.'/../storage/framework/testing/app';
        foreach (['logs', 'framework/cache/data', 'framework/sessions', 'framework/views', 'app/public', 'app/mpdf'] as $directory) {
            if (! is_dir("$storage/$directory")) {
                mkdir("$storage/$directory", 0777, true);
            }
        }
        $app->useStoragePath($storage);

        $app->make(Kernel::class)->bootstrap();

        IsolatedDatabaseGuard::check($app);

        return $app;
    }
}
