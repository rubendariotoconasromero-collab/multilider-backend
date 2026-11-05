<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;

final class IsolatedDatabaseGuard
{
    public static function allowedName(string $name): bool
    {
        return $name === 'multilider_tests_isolated'
            || ($name === getenv('MULTILIDER_F5_RESTORED_MYSQL')
                && preg_match('/^multilider_f5_restore_[a-f0-9]{32}$/D', $name));
    }

    public static function check(Application $app): void
    {
        $db = $app['config']->get('database.connections.mysql');
        if (! $app->environment('testing') || $app['config']->get('database.default') !== 'mysql'
            || $db['host'] !== '127.0.0.1' || ! self::allowedName($db['database'])
            || $db['username'] !== 'multilider_test' || (int) $db['port'] !== (int) getenv('MULTILIDER_TEST_PORT')
            || (int) $db['port'] === 3306 || ! empty($db['url'])) {
            throw new \RuntimeException('Conexión de pruebas insegura: no se permite migrar esta base.');
        }
        if ((int) $app['db']->connection()->selectOne('SELECT @@port AS port')->port !== (int) $db['port']) {
            throw new \RuntimeException('El servidor SQL no es la instancia aislada.');
        }
    }
}
