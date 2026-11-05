<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportCatalogMaps extends Command
{
    protected $signature = 'catalogo:mapas {file} {--apply}';

    protected $description = 'Valida o registra correspondencias explícitas revisadas; no copia entidades.';

    public function handle(): int
    {
        $manifest = json_decode(file_get_contents($this->argument('file')), true, 64, JSON_THROW_ON_ERROR);
        Validator::make($manifest, ['database' => 'required|string', 'dataset' => 'required|string|max:100', 'provenance' => 'required|string|max:255',
            'maps' => 'required|array|min:1', 'maps.*.entity' => 'required|in:propiedad,propietario,ubicacion,ciudad,distrito,sector,caracteristica,imagen',
            'maps.*.source_id' => 'required|string|max:100', 'maps.*.target_id' => 'required|string|max:100',
            'maps.*.target_service' => 'required|in:propiedades', 'maps.*.review_status' => 'required|in:approved'])->validate();
        if ($manifest['database'] !== DB::connection()->getDatabaseName()) {
            $this->error('La base destino no coincide con el manifiesto.');

            return self::FAILURE;
        }
        $rows = array_map(fn ($row) => $row + ['dataset' => $manifest['dataset'], 'provenance' => $manifest['provenance']], $manifest['maps']);
        foreach (['source_id', 'target_id'] as $side) {
            $keys = array_map(fn ($row) => $row['entity'].'|'.$row['target_service'].'|'.$row[$side], $rows);
            if (count(array_unique($keys)) !== count($keys)) {
                $this->error('Correspondencia ambigua.');

                return self::FAILURE;
            }
        }
        DB::transaction(function () use ($rows) {
            DB::table('catalog_mutex')->where('id', 1)->lockForUpdate()->first();
            foreach ($rows as $row) {
                $query = DB::table('catalog_identity_maps')->where('dataset', $row['dataset'])->where('entity', $row['entity'])->where('target_service', $row['target_service']);
                $existing = (clone $query)->where('source_id', $row['source_id'])->first();
                if ($existing && ($existing->target_id !== $row['target_id'] || $existing->review_status !== 'approved')) {
                    throw new \RuntimeException('Mapa incompatible; revisión requerida.');
                }
                if ((clone $query)->where('target_id', $row['target_id'])->where('source_id', '!=', $row['source_id'])->exists()) {
                    throw new \RuntimeException('Destino ya vinculado.');
                }
                if (! $existing && $this->option('apply')) {
                    DB::table('catalog_identity_maps')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        $this->info($this->option('apply') ? 'Correspondencias registradas; entidades intactas.' : 'Manifiesto válido. Sin --apply no se escribe.');

        return self::SUCCESS;
    }
}
