<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MailArchiveBackup;

class MailArchiveCommand extends Command
{
    protected $signature = 'correo:archivo {action : backup, verify, restore o cleanup} {--path= : Directorio privado del respaldo}';
    protected $description = 'Respalda, verifica o recupera el archivo documental del modo y dataset actuales.';

    public function handle(MailArchiveBackup $backup): int
    {
        try {
            if ($this->argument('action') === 'backup') $this->line($backup->create());
            elseif ($this->argument('action') === 'verify' && $this->option('path')) { $data = $backup->verify($this->option('path')); $this->info('Respaldo íntegro: '.count($data['documents']).' documentos.'); }
            elseif ($this->argument('action') === 'restore' && $this->option('path')) { $backup->restore($this->option('path')); $this->info('Archivo recuperado; se conservaron los registros existentes.'); }
            elseif ($this->argument('action') === 'cleanup') $this->info('Copias temporales retiradas: '.$backup->cleanup());
            else throw new \RuntimeException('Acción no válida. Utiliza backup, verify --path= o restore --path=.');
            return self::SUCCESS;
        } catch (\Throwable $error) { $this->error('No se completó la operación. Revisa el respaldo privado, su modo/dataset y los archivos; no se muestran datos sensibles.'); return self::FAILURE; }
    }
}
