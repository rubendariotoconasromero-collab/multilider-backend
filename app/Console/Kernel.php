<?php

namespace App\Console;

use App\Architecture\ArchitectureMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if (! app(ArchitectureMode::class)->isLocal()) {
            $schedule->command('identity:retry-revocations')->everyMinute()->withoutOverlapping();
        }
        if (app(ArchitectureMode::class)->isLocal()) {
            $schedule->command('catalogo:limpiar-imagenes')->everyFifteenMinutes()->withoutOverlapping();
            if (config('services.libelula.reconciliation_enabled', false)) {
                $schedule->command('payments:reconcile-tests')->everyMinute()->withoutOverlapping();
            }
        }
        // F2: legacy upserts must not overwrite business tables in either mode.
        // Register new projection jobs only after their identity/ownership is defined.
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
