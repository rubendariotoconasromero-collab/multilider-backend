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
        if (!config('services.microservice_sync_enabled')) {
            return;
        }
        // $schedule->command('inspire')->hourly();

        $schedule->command('propiedades:sync')
            ->everyTwoMinutes()
            ->withoutOverlapping();

        $schedule->command('usuarios:sync')
            ->everyTwoMinutes()
            ->withoutOverlapping();
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
