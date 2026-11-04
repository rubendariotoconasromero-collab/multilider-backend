<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncUsuariosMicroservicio extends Command
{
    protected $signature = 'usuarios:sync';
    protected $description = 'Sincronización antigua deshabilitada; requiere proyecciones con identidad estable.';

    public function handle(): int
    {
        $this->warn('INTEGRATION_NOT_READY: sincronización antigua bloqueada en ambos modos; no se realizaron solicitudes ni escrituras.');
        return self::FAILURE;
    }
}
