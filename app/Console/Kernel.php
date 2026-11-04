<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if (!app(\App\Architecture\ArchitectureMode::class)->isLocal()) {
            $schedule->command('identity:retry-revocations')->everyMinute()->withoutOverlapping();
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
