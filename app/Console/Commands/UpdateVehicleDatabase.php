<?php

namespace App\Console\Commands;

use App\Jobs\UpdateVehicleDatabaseJob;
use Illuminate\Console\Command;

class UpdateVehicleDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vehicles:update-database {--sync : Run synchronously instead of dispatching a job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update and maintain the vehicle database with validation and statistics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting vehicle database maintenance...');
        
        if ($this->option('sync')) {
            // Run synchronously
            $job = new UpdateVehicleDatabaseJob();
            $job->handle();
            $this->info('Vehicle database maintenance completed synchronously.');
        } else {
            // Dispatch as job
            UpdateVehicleDatabaseJob::dispatch();
            $this->info('Vehicle database maintenance job dispatched to queue.');
        }
        
        return 0;
    }
}
