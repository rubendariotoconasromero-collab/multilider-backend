<?php

namespace App\DataMigration;

use PDO;
use RuntimeException;

final class Target
{
    private bool $mysql;
    private array $metadata = [];
    private array $planned = [];
    private array $keys = [];
    private array $plannedRemovals = [];
    public array $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0];

    public function __construct(private PDO $db, private string $dataset, private string $mode)
    {
        $this->mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    public function initialize(): void
    {
        if ($this->mode !== 'apply') {
            return;
        }
        if ($this->mysql) {
            $lock = $this->select('SELECT GET_LOCK(?, 0)', ['multilider-f7'])->fetchColumn();
            if ((int) $lock !== 1) {
                throw new RuntimeException('Ya hay otra carga de Seguridad en ejecución.');
            }
            $this->db->exec('CREATE TABLE IF NOT EXISTS f7_import_rows (
                dataset varchar(64) NOT NULL, entity varchar(64) NOT NULL, source_key varchar(64) NOT NULL,
                target_table varchar(64) NOT NULL, locator varchar(240) NOT NULL,
                source_hash char(64) NOT NULL, target_hash char(64) NOT NULL, fields varchar(1800) NOT NULL,
                removed smallint NOT NULL DEFAULT 0, PRIMARY KEY(dataset, entity, source_key),
                UNIQUE(target_table, locator)) ENGINE=InnoDB');
        } else {
            $lock = $this->db->query("SET NOCOUNT ON; DECLARE @r int; EXEC @r=sp_getapplock @Resource='multilider-f7', @LockMode='Exclusive', @LockOwner='Session', @LockTimeout=0; SELECT @r")->fetchColumn();
            if ($lock === false || (int) $lock < 0) {
                throw new RuntimeException('Ya hay otra carga del servicio en ejecución.');
            }
            $this->db->exec("IF OBJECT_ID('dbo.f7_import_rows','U') IS NULL CREATE TABLE dbo.f7_import_rows (
                dataset varchar(64) NOT NULL, entity varchar(64) NOT NULL, source_key varchar(64) NOT NULL,
                target_table varchar(64) NOT NULL, locator varchar(240) NOT NULL,
                source_hash char(64) NOT NULL, target_hash char(64) NOT NULL, fields varchar(1800) NOT NULL,
                removed smallint NOT NULL DEFAULT 0, PRIMARY KEY(dataset, entity, source_key),
                UNIQUE(target_table, locator))");
        }
        unset($this->metadata['f7_import_rows']);
    }

    public function assertComplete(string $entity, array $expected): void
    {
        if ($this->columns('f7_import_rows') === []) {
            return;
        }
        $rows = $this->select('SELECT source_key FROM f7_import_rows WHERE dataset=? AND entity=? AND removed=0 ORDER BY source_key',
            [$this->dataset, $entity])->fetchAll(PDO::FETCH_COLUMN);
        $present = array_fill_keys(array_map('strval', $expected), true);
        foreach ($rows as $id) {
            if (! isset($present[$id]) && ! isset($this->plannedRemovals[$entity][$id])) {
                throw new RuntimeException("Hay una baja sin conciliar en $entity/$id.");
            }
        }
    }

    public function find(string $table, array $locator, array $fields): ?array
    {
        $columns = implode(',', array_map($this->quote(...), $fields));
        $row = $this->select("SELECT $columns FROM ".$this->quote($table).' WHERE '.$this->where($locator), array_values($locator))->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function mappedValues(string $entity, string $sourceKey, array $fields): ?array
    {
        $receipt = $this->receipt($entity, $sourceKey);
        if ($receipt === null || (int) $receipt['removed'] !== 0) {
            return null;
        }
        $locator = json_decode($receipt['locator'], true, flags: JSON_THROW_ON_ERROR);
        $this->requireRowHash($receipt['target_table'], $locator, json_decode($receipt['fields'], true, flags: JSON_THROW_ON_ERROR),
            $receipt['target_hash']);

        return $this->find($receipt['target_table'], $locator, $fields);
    }

    public function row(string $entity, string $sourceKey, string $table, array $values, string $sourceHash, ?string $previousHash = null, array $locator = [], array $unique = []): int|string
    {
        $fields = array_keys($values);
        $this->validate($table, $values + $locator);
        foreach ($unique as $key) {
            if (in_array(null, $key, true)) {
                continue;
            }
            $fingerprint = hash('sha256', mb_strtolower(Snapshot::json($key)));
            if (isset($this->keys[$table][$fingerprint]) && $this->keys[$table][$fingerprint] !== $sourceKey) {
                throw new RuntimeException("Clave duplicada de origen en $table.");
            }
            $this->keys[$table][$fingerprint] = $sourceKey;
        }
        $record = $this->receipt($entity, $sourceKey);
        if ($record !== null) {
            if ($record['target_table'] !== $table || (int) $record['removed'] !== 0) {
                throw new RuntimeException('La equivalencia anterior requiere revisión.');
            }
            $locator = json_decode($record['locator'], true, flags: JSON_THROW_ON_ERROR);
            $current = $this->find($table, $locator, json_decode($record['fields'], true, flags: JSON_THROW_ON_ERROR));
            if ($current === null || $this->hash($table, $current) !== $record['target_hash']) {
                throw new RuntimeException('El destino cambió fuera de la importación.');
            }
            $newHash = $this->hash($table, $values);
            if ($newHash === $record['target_hash'] && $sourceHash === $record['source_hash']) {
                $this->counts['unchanged']++;

                return reset($locator);
            }
            if ($this->mode === 'verify' || $previousHash === null || $previousHash !== $record['source_hash']) {
                throw new RuntimeException('Cambio de huella sin delta aplicable.');
            }
            if ($this->mode === 'apply') {
                $this->transaction(function () use ($entity, $sourceKey, $table, $values, $locator, $sourceHash, $newHash, $fields) {
                    $sets = implode(',', array_map(fn ($name) => $this->quote($name).'=?', array_keys($values)));
                    $this->select('UPDATE '.$this->quote($table)." SET $sets WHERE ".$this->where($locator), [...array_values($values), ...array_values($locator)]);
                    $this->requireRowHash($table, $locator, $fields, $newHash, $values);
                    $this->select('UPDATE f7_import_rows SET source_hash=?,target_hash=?,fields=? WHERE dataset=? AND entity=? AND source_key=?',
                        [$sourceHash, $newHash, Snapshot::json($fields), $this->dataset, $entity, $sourceKey]);
                });
            }
            $this->counts['updated']++;

            return reset($locator);
        }
        if ($this->mode === 'verify') {
            throw new RuntimeException('Falta la equivalencia del destino.');
        }
        foreach ([...$unique, $locator] as $key) {
            if ($key !== [] && ! in_array(null, $key, true) && $this->find($table, $key, array_keys($key)) !== null) {
                throw new RuntimeException("Colisión con una fila de $table sin equivalencia aprobada.");
            }
        }
        if ($this->mode === 'plan') {
            $this->counts['inserted']++;

            return $locator === [] ? ($this->planned[$entity][$sourceKey] ??= -count($this->planned[$entity] ?? []) - 1) : reset($locator);
        }
        $result = $this->transaction(function () use ($entity, $sourceKey, $table, $values, $sourceHash, $fields, $locator) {
            $insert = $values + $locator;
            $columns = implode(',', array_map($this->quote(...), array_keys($insert)));
            $markers = implode(',', array_fill(0, count($insert), '?'));
            $output = ! $this->mysql && $locator === [] ? ' OUTPUT INSERTED.Id' : '';
            $query = $this->select('INSERT INTO '.$this->quote($table)." ($columns)$output VALUES ($markers)", array_values($insert));
            if ($locator === []) {
                $id = (int) ($this->mysql ? $this->db->lastInsertId() : $query->fetchColumn());
                $query->closeCursor();
                if ($id <= 0) {
                    throw new RuntimeException('El destino no devolvió un identificador.');
                }
                $locator = [($this->mysql ? 'id' : 'Id') => $id];
            }
            $hash = $this->hash($table, $values);
            $this->requireRowHash($table, $locator, $fields, $hash, $values);
            $this->select('INSERT INTO f7_import_rows(dataset,entity,source_key,target_table,locator,source_hash,target_hash,fields) VALUES (?,?,?,?,?,?,?,?)',
                [$this->dataset, $entity, $sourceKey, $table, Snapshot::json($locator), $sourceHash, $hash, Snapshot::json($fields)]);

            return reset($locator);
        });
        $this->counts['inserted']++;
        static $commits = 0;
        if (++$commits === (int) getenv('MULTILIDER_F7_PAUSE_AFTER')) {
            throw new MigrationPaused;
        }

        return $result;
    }

    public function remove(string $entity, string $sourceKey, string $previousHash, ?array $retained): void
    {
        $record = $this->receipt($entity, $sourceKey);
        if ($record === null || $record['source_hash'] !== $previousHash) {
            throw new RuntimeException('La baja no coincide con el lote anterior.');
        }
        $locator = json_decode($record['locator'], true, flags: JSON_THROW_ON_ERROR);
        $table = $record['target_table'];
        if ((int) $record['removed'] === 1) {
            if ($retained !== null) {
                $this->requireRowHash($table, $locator, array_keys($retained), $this->hash($table, $retained));
            } elseif ($this->find($table, $locator, array_keys($locator)) !== null) {
                throw new RuntimeException('La relación eliminada reapareció en el destino.');
            }

            return;
        }
        $this->requireRowHash($table, $locator, json_decode($record['fields'], true, flags: JSON_THROW_ON_ERROR), $record['target_hash']);
        if ($this->mode === 'plan') {
            $this->plannedRemovals[$entity][$sourceKey] = true;
        }
        if ($this->mode === 'verify') {
            throw new RuntimeException('Falta aplicar una baja.');
        }
        if ($this->mode === 'apply') {
            $this->transaction(function () use ($entity, $sourceKey, $table, $locator, $retained) {
                if ($retained === null) {
                    $this->select('DELETE FROM '.$this->quote($table).' WHERE '.$this->where($locator), array_values($locator));
                } else {
                    $sets = implode(',', array_map(fn ($name) => $this->quote($name).'=?', array_keys($retained)));
                    $this->select('UPDATE '.$this->quote($table)." SET $sets WHERE ".$this->where($locator), [...array_values($retained), ...array_values($locator)]);
                }
                $this->select('UPDATE f7_import_rows SET removed=1 WHERE dataset=? AND entity=? AND source_key=?', [$this->dataset, $entity, $sourceKey]);
            });
        }
        $this->counts['removed']++;
    }

    private function requireRowHash(string $table, array $locator, array $fields, string $hash, ?array $expected = null): void
    {
        $actual = $this->find($table, $locator, $fields);
        if ($actual === null || $this->hash($table, $actual) !== $hash) {
            $differences = [];
            foreach ($expected ?? [] as $name => $value) {
                if ($actual === null || $this->hash($table, [$name => $value]) !== $this->hash($table, [$name => $actual[$name]])) {
                    $differences[] = $name;
                }
            }
            $suffix = $differences === [] ? '' : ' ('.implode(', ', $differences).')';
            throw new RuntimeException("La fila de $table difiere de la transformación$suffix.");
        }
    }

    private function receipt(string $entity, string $sourceKey): ?array
    {
        if ($this->columns('f7_import_rows') === []) {
            return null;
        }
        $row = $this->select('SELECT * FROM f7_import_rows WHERE dataset=? AND entity=? AND source_key=?', [$this->dataset, $entity, $sourceKey])->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function validate(string $table, array $data): void
    {
        $columns = $this->columns($table);
        foreach ($data as $name => $value) {
            $column = $columns[$name] ?? throw new RuntimeException("Columna ausente en $table.$name.");
            $limit = (int) $column['maximum_length'];
            if (($value === null && $column['nullable'] !== 'YES') || (is_string($value) && $limit > 0 && mb_strlen($value) > $limit)) {
                throw new RuntimeException("Valor incompatible con $table.$name.");
            }
        }
    }

    private function columns(string $table): array
    {
        if (! array_key_exists($table, $this->metadata)) {
            $scope = $this->mysql ? 'TABLE_SCHEMA=DATABASE()' : "TABLE_SCHEMA='dbo'";
            $query = $this->select("SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, CHARACTER_MAXIMUM_LENGTH AS maximum_length
                FROM INFORMATION_SCHEMA.COLUMNS WHERE $scope AND TABLE_NAME=?", [$table]);
            $this->metadata[$table] = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'name');
        }

        return $this->metadata[$table];
    }

    private function hash(string $table, array $values): string
    {
        $columns = $this->columns($table);
        foreach ($values as $name => &$value) {
            if ($value === null) {
                continue;
            }
            $type = $columns[$name]['type'];
            $value = is_bool($value) ? (string) (int) $value : (string) $value;
            if (in_array($type, ['float', 'real', 'double'], true)) {
                // SQL Server can return a longer binary representation of the same coordinate.
                $value = sprintf('%.15g', (float) $value);
            }
            if (in_array($type, ['decimal', 'numeric'], true)) {
                $value = preg_replace('/^(-?)\./', '${1}0.', $value);
            }
            if (in_array($type, ['decimal', 'numeric', 'datetime', 'datetime2'], true) && str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
            if ($type === 'uniqueidentifier') {
                $value = strtolower($value);
            }
        }
        unset($value);

        return hash('sha256', Snapshot::json($values));
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new RuntimeException('Identificador SQL inválido.');
        }

        return $this->mysql ? "`$identifier`" : "[$identifier]";
    }

    private function where(array $locator): string
    {
        if ($locator === []) {
            throw new RuntimeException('Falta el identificador de destino.');
        }

        return implode(' AND ', array_map(fn ($name) => $this->quote($name).'=?', array_keys($locator)));
    }

    private function select(string $sql, array $values): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute(array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $values));

        return $query;
    }

    private function transaction(callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $callback();
            static $attempts = 0;
            if (++$attempts === (int) getenv('MULTILIDER_F7_PAUSE_BEFORE_COMMIT')) {
                throw new MigrationPaused('Carga pausada antes de confirmar el lote.');
            }
            $this->db->commit();

            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
