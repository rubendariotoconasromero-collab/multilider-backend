<?php

use App\DataMigration\Connections;
use App\DataMigration\Importer;
use App\DataMigration\MigrationPaused;
use App\DataMigration\Snapshot;

require __DIR__.'/../vendor/autoload.php';

try {
    $started = hrtime(true);
    $backend = dirname(__DIR__);
    $root = Connections::root($backend);
    [$action, $dataset, $label] = array_pad(array_slice($argv, 1, 3), 3, '');
    Snapshot::requireDataset($dataset);
    if (! preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $label)) {
        throw new RuntimeException('Indica un nombre simple para el paquete.');
    }
    $path = $root.'/'.$label;
    if ($action === 'archive-local') {
        Connections::archiveLocal($backend, $argv[4] ?? '', $path.'.sql');
        echo "Respaldo MySQL guardado con su huella.\n";
        exit(0);
    }
    if (in_array($action, ['capture-local', 'capture'], true)) {
        $local = $action === 'capture-local';
        $source = $local ? Connections::localSource($backend, $argv[4] ?? '') : Connections::isolatedSource();
        $storage = $local ? ($argv[5] ?? $backend.'/storage/app/public') : $root.'/source-files';
        $snapshot = Snapshot::capture($source, $storage, $path, $dataset);
        $counts = array_column($snapshot->manifest['tables'], 'rows');
        echo 'Captura: '.count($counts).' tablas, '.array_sum($counts).' filas y '.count($snapshot->manifest['assets'])." referencias de archivos.\n";
        exit(0);
    }
    if (! in_array($action, ['plan', 'apply', 'verify', 'verify-source'], true)) {
        throw new RuntimeException('Operación válida: archive-local, capture-local, capture, plan, apply, verify o verify-source.');
    }
    $snapshot = Snapshot::open($path);
    if (! Snapshot::inside($root, $snapshot->directory)) {
        throw new RuntimeException('El paquete está fuera de su directorio aislado.');
    }
    if ($snapshot->manifest['dataset'] !== $dataset) {
        throw new RuntimeException('El dataset solicitado no coincide con el manifiesto.');
    }
    if ($action === 'verify-source') {
        $storage = $root.'/laravel/app/public';
        $assets = $snapshot->restoreSourceFiles($storage);
        $copy = Snapshot::capture(Connections::isolatedSource(), $storage, $root.'/restaurado-'.bin2hex(random_bytes(6)), $dataset);
        if ($copy->manifest['tables'] !== $snapshot->manifest['tables'] || $copy->manifest['assets'] !== $snapshot->manifest['assets']
            || $copy->manifest['schema'] !== $snapshot->manifest['schema']) {
            throw new RuntimeException('El origen restaurado no coincide con el paquete capturado.');
        }
        echo "Restauración MySQL validada por tabla, migraciones y $assets referencias de archivos.\n";
        exit(0);
    }
    $previous = $argv[4] ?? null;
    if ($previous !== null && ! preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $previous)) {
        throw new RuntimeException('El nombre del paquete previo no es válido.');
    }
    $targets = Connections::targets();
    $prior = $previous === null ? null : Snapshot::open($root.'/'.$previous);
    if ($prior !== null && ! Snapshot::inside($root, $prior->directory)) {
        throw new RuntimeException('El paquete anterior está fuera de su directorio aislado.');
    }
    $report = (new Importer($snapshot, $targets, $root, $action === 'apply' ? 'plan' : $action, $prior))->run();
    if ($action === 'apply' && $report['summary']['blocked'] === 0) {
        $report = (new Importer($snapshot, $targets, $root, 'apply', $prior))->run();
    }
    $report['duration_ms'] = (int) ((hrtime(true) - $started) / 1000000);
    Snapshot::writeJson("$root/$label-$action.json", $report);
    echo strtoupper($action).': '.Snapshot::json($report['summary']).PHP_EOL;
    exit($report['summary']['blocked'] === 0 ? 0 : 2);
} catch (MigrationPaused $paused) {
    echo ($paused->getMessage() ?: 'Carga pausada tras confirmar una fila y su equivalencia.').PHP_EOL;
    exit(75);
} catch (PDOException $error) {
    fwrite(STDERR, 'Error SQL durante la migración. Código '.$error->getCode().PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage().PHP_EOL
        : 'Fallo interno '.get_class($error).' en '.basename($error->getFile()).':'.$error->getLine().'.'.PHP_EOL);
    exit(1);
}
