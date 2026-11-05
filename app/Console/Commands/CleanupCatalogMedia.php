<?php

namespace App\Console\Commands;

use App\Architecture\ArchitectureMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupCatalogMedia extends Command
{
    protected $signature = 'catalogo:limpiar-imagenes';

    protected $description = 'Elimina archivos retirados después del commit y huérfanos propios de F4.';

    public function handle(ArchitectureMode $mode): int
    {
        if (! $mode->isLocal()) {
            $this->error('El almacenamiento remoto se mantiene en Propiedades.');

            return self::FAILURE;
        }
        foreach (DB::table('catalog_media_cleanup')->orderBy('created_at')->limit(100)->get() as $row) {
            $private = $row->disk === 'local';
            $pattern = $private ? '~^catalog-media/objects/f4-[a-f0-9-]{36}\.(jpg|jpeg|png|gif)$~D' : '~^propiedades/[1-9][0-9]*/[a-zA-Z0-9.-]+$~D';
            if (! in_array($row->disk, ['public', 'local'], true) || ! preg_match($pattern, $row->path)) {
                continue;
            }
            $disk = Storage::disk($row->disk);
            $referenced = $private ? DB::table('imagenes_propiedades')->where('storage_key', $row->path)->exists() : DB::table('imagenes_propiedades')->where('url', '/storage/'.$row->path)->exists();
            if (! $referenced && $disk->exists($row->path) && ! $disk->delete($row->path)) {
                continue;
            }
            DB::table('catalog_media_cleanup')->where('id', $row->id)->delete();
        }
        foreach (['public' => 'propiedades', 'local' => 'catalog-media/objects'] as $name => $directory) {
            $disk = Storage::disk($name);
            foreach ($disk->allFiles($directory) as $path) {
                $pattern = $name === 'local' ? '~^catalog-media/objects/f4-[a-f0-9-]{36}\.(jpg|jpeg|png|gif)$~D' : '~^propiedades/[1-9][0-9]*/f4-[a-f0-9-]{36}\.(jpg|jpeg|png|gif)$~D';
                if (! preg_match($pattern, $path) || $disk->lastModified($path) >= now()->subDay()->timestamp) {
                    continue;
                }
                $referenced = $name === 'local' ? DB::table('imagenes_propiedades')->where('storage_key', $path)->exists() : DB::table('imagenes_propiedades')->where('url', '/storage/'.$path)->exists();
                if (! $referenced) {
                    $disk->delete($path);
                }
            }
        }

        return self::SUCCESS;
    }
}
