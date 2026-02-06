<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\UpdateShippingSchedulesJob;
use App\Models\ScheduleSyncLog;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            $syncLog = ScheduleSyncLog::create([
                'sync_type' => 'nightly',
                'status' => 'running',
                'started_at' => now(),
                'details' => [
                    'triggered_by' => 'scheduler',
                ],
            ]);

            UpdateShippingSchedulesJob::dispatchSync($syncLog->id);
        })
            ->dailyAt('03:00')
            ->timezone('Europe/Brussels')
            ->withoutOverlapping(60);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}


