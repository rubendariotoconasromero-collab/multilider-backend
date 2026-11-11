<?php

namespace App\DataMigration;

use Dotenv\Dotenv;
use PDO;
use RuntimeException;

final class Connections
{
    public static function root(string $backend): string
    {
        $root = realpath(getenv('MULTILIDER_F7_ROOT') ?: '.');
        $testing = realpath($backend.'/storage/framework/testing');
        if ($root === false || $testing === false || dirname($root) !== $testing || ! preg_match('/^f7-[a-f0-9]{32}$/D', basename($root))) {
            throw new RuntimeException('Usa un directorio privado f7-UUID dentro de storage/framework/testing.');
        }

        return $root;
    }

    public static function localSource(string $backend, string $name): PDO
    {
        $settings = self::localSettings($backend, $name);

        return self::mysql($name, 3306, $settings['DB_USERNAME'], $settings['DB_PASSWORD']);
    }

    private static function localSettings(string $backend, string $name): array
    {
        $settings = Dotenv::parse(file_get_contents($backend.'/.env'));
        if (($settings['DB_HOST'] ?? null) !== '127.0.0.1' || ($settings['DB_PORT'] ?? null) !== '3306'
            || ! in_array($name, ['db_multilider', 'multilider_db', 'laravel'], true)) {
            throw new RuntimeException('El origen local no coincide con Laragon.');
        }

        return $settings;
    }

    public static function archiveLocal(string $backend, string $name, string $file): void
    {
        $settings = self::localSettings($backend, $name);
        if (file_exists($file)) {
            throw new RuntimeException('El archivo de respaldo ya existe.');
        }
        $previous = getenv('MYSQL_PWD');
        putenv('MYSQL_PWD='.$settings['DB_PASSWORD']);
        try {
            $process = proc_open(['mysqldump', '--no-defaults', '--host=127.0.0.1', '--port=3306',
                '--user='.$settings['DB_USERNAME'], '--single-transaction', '--skip-lock-tables', '--skip-add-locks',
                '--set-gtid-purged=OFF', '--no-tablespaces', '--hex-blob', '--skip-add-drop-table', $name],
                [0 => ['pipe', 'r'], 1 => ['file', $file, 'wb'], 2 => ['file', $file.'.err', 'wb']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('No se pudo iniciar mysqldump.');
            }
            fclose($pipes[0]);
            if (proc_close($process) !== 0 || filesize($file) <= 0) {
                throw new RuntimeException('No se obtuvo un respaldo MySQL consistente.');
            }
        } finally {
            putenv($previous === false ? 'MYSQL_PWD' : 'MYSQL_PWD='.$previous);
        }

        Snapshot::writeJson($file.'.json', ['source' => $name, 'sha256' => hash_file('sha256', $file), 'cutoff_utc' => gmdate('c')]);
    }

    public static function isolatedSource(): PDO
    {
        $port = self::isolatedPort();

        return self::mysql('multilider_tests_isolated', $port, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    }

    public static function targets(): array
    {
        $port = self::isolatedPort();
        $identity = getenv('MULTILIDER_IDENTITY_DATABASE') ?: '';
        $restored = getenv('MULTILIDER_F7_RESTORED_IDENTITY');
        if (($identity !== 'multilider_identity_tests_isolated' && ($identity !== $restored || ! preg_match('/^multilider_f5_restore_[a-f0-9]{32}$/D', $identity)))
            || getenv('MULTILIDER_IDENTITY_USER') !== 'identity_test') {
            throw new RuntimeException('La base aislada de Seguridad no coincide.');
        }
        $result = ['seguridad' => self::mysql($identity, $port, 'identity_test', getenv('MULTILIDER_IDENTITY_PASSWORD'))];
        $services = [
            'propiedades' => ['PROPERTIES', 'properties_f3'],
            'comercial' => ['COMERCIAL', 'comercial_f4'],
            'finanzas' => ['FINANZAS', 'finanzas_f5'],
        ];
        foreach ($services as $name => [$setting, $prefix]) {
            $config = getenv("MULTILIDER_{$setting}_TEST_CONNECTION") ?: '';
            if (! preg_match("/^Server=localhost;Database=(multilider_{$prefix}_test_[a-f0-9]{32});Integrated Security=True;Encrypt=True;TrustServerCertificate=True;Connect Timeout=5$/D", $config, $match)) {
                throw new RuntimeException("La base aislada de $name no coincide.");
            }
            $database = $match[1];
            $pdo = new PDO("sqlsrv:Server=localhost;Database=$database;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5", null, null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($pdo->query('SELECT DB_NAME()')->fetchColumn() !== $database) {
                throw new RuntimeException("El servidor devolvió una base distinta para $name.");
            }
            $result[$name] = $pdo;
        }

        return $result;
    }

    private static function isolatedPort(): int
    {
        $port = (int) getenv('MULTILIDER_TEST_PORT');
        if (getenv('MULTILIDER_TEST_ISOLATED') !== '1' || getenv('DB_HOST') !== '127.0.0.1'
            || $port <= 1024 || $port === 3306 || $port !== (int) getenv('DB_PORT')) {
            throw new RuntimeException('La importación requiere el runner aislado.');
        }

        return $port;
    }

    private static function mysql(string $name, int $port, string $user, string $password): PDO
    {
        $db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$name;charset=utf8mb4", $user, $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        if ((int) $db->query('SELECT @@port')->fetchColumn() !== $port || $db->query('SELECT DATABASE()')->fetchColumn() !== $name) {
            throw new RuntimeException('La conexión MySQL no coincide con el origen solicitado.');
        }

        return $db;
    }
}
