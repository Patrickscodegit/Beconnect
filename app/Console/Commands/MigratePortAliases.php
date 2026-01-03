<?php

namespace App\Console\Commands;

use Database\Seeders\PortAliasesSeeder;
use Illuminate\Console\Command;

class MigratePortAliases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ports:migrate-aliases';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate hardcoded port mappings and shipping_codes to port_aliases table (idempotent)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Running port aliases migration...');
        
        $seeder = new PortAliasesSeeder();
        $seeder->setCommand($this);
        $seeder->run();

        $this->info('Migration completed successfully!');
        
        return Command::SUCCESS;
    }
}

