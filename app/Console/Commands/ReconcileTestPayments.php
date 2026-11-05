<?php
namespace App\Console\Commands;

use App\Architecture\ArchitectureMode;
use App\Services\LocalPaymentTests;
use Illuminate\Console\Command;

final class ReconcileTestPayments extends Command
{
    protected $signature = 'payments:reconcile-tests {--reference= : Referencia TEMP de una orden propia}';
    protected $description = 'Consulta órdenes de prueba pendientes; nunca registra otro cobro ni liquida deuda real';
    public function handle(ArchitectureMode $mode, LocalPaymentTests $payments): int
    {
        if (! $mode->isLocal()) { $this->error('En remoto, la conciliación pertenece al worker de Finanzas.'); return self::FAILURE; }
        $reference = $this->option('reference');
        if ($reference && ! preg_match('/^TEMP-[a-f0-9-]{36}$/Di', $reference)) { $this->error('Referencia inválida.'); return self::FAILURE; }
        $this->info('Órdenes de prueba verificadas: '.$payments->reconcile($reference ?: null));
        return self::SUCCESS;
    }
}
