<?php

namespace App\DataMigration;

use PDO;
use RuntimeException;

final class Snapshot
{
    public const TABLES = [
        'users', 'roles', 'permisos', 'rol_permiso', 'rol_permiso_usuario',
        'ciudades', 'distritos', 'sectores_urbanos', 'propietarios', 'ubicaciones',
        'caracteristicas', 'propiedades', 'propiedad_propietario', 'caracteristica_propiedad', 'imagenes_propiedades',
        'clientes', 'asesores', 'notas_ventas', 'contratos', 'entregas',
        'metodos_pago', 'cuentas_bancarias', 'metodo_pago_cuenta_default', 'planes_pagos', 'reprogramaciones', 'cuotas',
        'pagos', 'ingresos', 'egresos', 'mi_empresa', 'campanas_correo', 'campana_destinatarios',
    ];

    private const OPTIONAL = ['campana_destinatarios'];

    private const ASSETS = [
        'imagenes_propiedades' => ['url'],
        'asesores' => ['foto'],
        'contratos' => ['url_doc'],
        'entregas' => ['url_acta'],
        'mi_empresa' => ['logo', 'logo_login', 'logo_sidebar_compact', 'logo_sidebar', 'hero_image_1', 'hero_image_2', 'hero_image_3',
            'imagen_hero', 'imagen_nosotros_1', 'imagen_nosotros_2'],
    ];

    private function __construct(public readonly string $directory, public readonly array $manifest) {}

    public static function capture(PDO $source, string $storage, string $directory, string $dataset): self
    {
        self::requireDataset($dataset);
        if (file_exists($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('El directorio del manifiesto debe ser nuevo.');
        }
        $storage = realpath($storage);
        if ($storage === false) {
            throw new RuntimeException('El almacenamiento de origen no existe.');
        }
        mkdir($directory.'/files', 0700);
        $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $manifest = [
                'format' => 1, 'dataset' => $dataset, 'cutoff_utc' => gmdate('c'),
                'source' => $source->query('SELECT DATABASE()')->fetchColumn(),
                'schema' => $source->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN),
                'tables' => [], 'assets' => [],
            ];
            $tables = $source->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (self::TABLES as $table) {
                if (! isset($tables[$table]) && ! in_array($table, self::OPTIONAL, true)) {
                    throw new RuntimeException("Falta la tabla de origen $table.");
                }
                if (isset($tables[$table]) && $tables[$table] !== 'InnoDB') {
                    throw new RuntimeException("$table requiere un respaldo consistente antes de exportar.");
                }
                $file = "$directory/$table.jsonl";
                $out = fopen($file, 'xb');
                if ($out === false) {
                    throw new RuntimeException("No se pudo crear el archivo de $table.");
                }
                $count = 0;
                try {
                    if (isset($tables[$table])) {
                        foreach (self::sourceRows($source, $table) as $row) {
                            if ($table === 'users') {
                                unset($row['remember_token']);
                            }
                            $line = self::json($row)."\n";
                            if (fwrite($out, $line) !== strlen($line)) {
                                throw new RuntimeException("Escritura incompleta de $table.");
                            }
                            foreach (self::ASSETS[$table] ?? [] as $column) {
                                if (! empty($row[$column])) {
                                    $key = "$table/{$row['id']}/$column";
                                    $manifest['assets'][$key] = self::captureAsset($storage, $directory, $row[$column]);
                                }
                            }
                            $count++;
                        }
                    }
                } finally {
                    fclose($out);
                }
                $manifest['tables'][$table] = ['rows' => $count, 'sha256' => hash_file('sha256', $file)];
            }
        } finally {
            $source->exec('ROLLBACK');
        }
        self::writeJson($directory.'/manifest.json', $manifest);

        return self::open($directory);
    }

    private static function sourceRows(PDO $source, string $table): iterable
    {
        if ($table === 'propiedad_propietario') {
            $cursor = [0, 0];
            $query = $source->prepare('SELECT * FROM propiedad_propietario WHERE (propiedad_id,propietario_id) > (?,?) ORDER BY propiedad_id,propietario_id LIMIT 500');
            do {
                $query->execute($cursor);
                $page = $query->fetchAll(PDO::FETCH_ASSOC);
                foreach ($page as $row) {
                    $cursor = [$row['propiedad_id'], $row['propietario_id']];
                    yield ['id' => sprintf('%020d:%020d', ...$cursor)] + $row;
                }
            } while (count($page) === 500);

            return;
        }
        $cursor = 0;
        $query = $source->prepare("SELECT * FROM `$table` WHERE id > ? ORDER BY id LIMIT 500");
        do {
            $query->execute([$cursor]);
            $page = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($page as $row) {
                $cursor = (int) $row['id'];
                yield $row;
            }
        } while (count($page) === 500);
    }

    private static function captureAsset(string $storage, string $directory, string $url): array
    {
        $relative = self::assetPath($url);
        if ($relative === null) {
            return ['error' => 'referencia_archivo_invalida'];
        }
        $source = realpath($storage.'/'.$relative);
        if ($source === false || ! is_file($source) || ! self::inside($storage, $source)) {
            return ['error' => 'archivo_no_disponible'];
        }
        $hash = hash_file('sha256', $source);
        $file = $directory.'/files/'.$hash;
        if ((! is_file($file) && ! copy($source, $file)) || hash_file('sha256', $file) !== $hash
            || hash_file('sha256', $source) !== $hash) {
            throw new RuntimeException('Un archivo cambió durante la captura.');
        }

        return ['sha256' => $hash, 'bytes' => filesize($file), 'type' => (new \finfo(FILEINFO_MIME_TYPE))->file($file)];
    }

    public static function assetPath(string $url): ?string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $relative = preg_replace('~^/?storage/~', '', $path);
        if (! is_string($relative) || ! preg_match('~^[\pL\pN _./()-]+$~uD', $relative)
            || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
            return null;
        }

        return $relative;
    }

    public function restoreSourceFiles(string $directory): int
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('No se pudo preparar la copia de almacenamiento.');
        }
        $root = realpath($directory);
        $count = 0;
        foreach (self::ASSETS as $table => $columns) {
            foreach ($this->rows($table) as $id => $row) {
                foreach ($columns as $column) {
                    if (empty($row[$column])) {
                        continue;
                    }
                    $asset = $this->asset("$table/$id/$column") ?? throw new RuntimeException('Falta un archivo en el manifiesto.');
                    $path = self::assetPath($row[$column]) ?? throw new RuntimeException('La ruta restaurada no es válida.');
                    $file = $root.'/'.$path;
                    $parent = dirname($file);
                    if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                        throw new RuntimeException('No se pudo crear el directorio restaurado.');
                    }
                    if (realpath($parent) !== $root && ! self::inside($root, realpath($parent) ?: '')) {
                        throw new RuntimeException('Directorio restaurado fuera del almacenamiento permitido.');
                    }
                    if ((! is_file($file) && ! copy($this->assetFile($asset['sha256']), $file))
                        || ! self::inside($root, realpath($file) ?: '') || hash_file('sha256', $file) !== $asset['sha256']) {
                        throw new RuntimeException('El archivo restaurado no coincide.');
                    }
                    $count++;
                }
            }
        }

        return $count;
    }

    public static function open(string $directory): self
    {
        $root = realpath($directory);
        if ($root === false) {
            throw new RuntimeException('El paquete de datos no existe.');
        }
        $manifest = json_decode(file_get_contents(self::containedFile($root, 'manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || array_keys($manifest['tables'] ?? []) !== self::TABLES) {
            throw new RuntimeException('El manifiesto tiene una versión o alcance incompatible.');
        }
        self::requireDataset($manifest['dataset']);
        foreach ($manifest['tables'] as $table => $entry) {
            $path = self::containedFile($root, "$table.jsonl");
            if (hash_file('sha256', $path) !== $entry['sha256']) {
                throw new RuntimeException("Huella inválida en $table.");
            }
        }
        foreach ($manifest['assets'] as $entry) {
            if (! isset($entry['error'])) {
                $hash = $entry['sha256'];
                $path = self::containedFile($root, 'files/'.self::requireHash($hash));
                if (filesize($path) !== $entry['bytes'] || hash_file('sha256', $path) !== $hash) {
                    throw new RuntimeException('El manifiesto no coincide con uno de los archivos.');
                }
            }
        }

        return new self($root, $manifest);
    }

    public function rows(string $table): iterable
    {
        $entry = $this->manifest['tables'][$table] ?? throw new RuntimeException('Tabla fuera del manifiesto.');
        $file = new \SplFileObject(self::containedFile($this->directory, "$table.jsonl"));
        $id = null;
        $count = 0;
        foreach ($file as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $next = $row['id'] ?? null;
            if ($table === 'propiedad_propietario') {
                $valid = is_string($next) && preg_match('/^\d{20}:\d{20}$/D', $next) && ($id === null || strcmp($id, $next) < 0);
            } else {
                $next = (int) $next;
                $valid = $next > ($id ?? 0);
            }
            if (! $valid) {
                throw new RuntimeException("Claves fuera de orden en $table.");
            }
            $id = $next;
            $count++;
            yield $id => $row;
        }
        if ($count !== $entry['rows']) {
            throw new RuntimeException("Conteo inválido en $table.");
        }
    }

    public function asset(string $key): ?array
    {
        $asset = $this->manifest['assets'][$key] ?? null;
        if (isset($asset['error'])) {
            throw new RuntimeException($asset['error']);
        }

        return $asset;
    }

    public function assetFile(string $hash): string
    {
        return self::containedFile($this->directory, 'files/'.self::requireHash($hash));
    }

    private static function requireHash(string $hash): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new RuntimeException('Huella de archivo inválida.');
        }

        return $hash;
    }

    public static function requireDataset(string $dataset): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/D', $dataset)) {
            throw new RuntimeException('El dataset no es válido.');
        }
    }

    public static function inside(string $root, string $path): bool
    {
        $prefix = rtrim($root, '/\\').DIRECTORY_SEPARATOR;

        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0 : str_starts_with($path, $prefix);
    }

    private static function containedFile(string $root, string $relative): string
    {
        $path = realpath($root.'/'.$relative);
        if ($path === false || ! is_file($path) || ! self::inside($root, $path)) {
            throw new RuntimeException('Archivo del paquete fuera de su directorio o faltante.');
        }

        return $path;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function writeJson(string $file, array $value): void
    {
        $json = self::json($value)."\n";
        if (file_put_contents($file, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('No se pudo guardar el resultado de la migración.');
        }
    }
}
