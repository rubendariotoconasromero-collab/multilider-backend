<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncPropiedadesMicroservicio extends Command
{
    protected $signature = 'propiedades:sync';
    protected $description = 'Sincronización antigua deshabilitada; requiere proyecciones con identidad estable.';

    public function handle(): int
    {
        $this->warn('INTEGRATION_NOT_READY: sincronización antigua bloqueada en ambos modos; no se realizaron solicitudes ni escrituras.');
        return self::FAILURE;
    }
}
