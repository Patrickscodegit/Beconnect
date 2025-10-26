<?php

namespace App\Console\Commands;

use App\Jobs\DispatchArticleExtraFieldsSyncJobs;
use Illuminate\Console\Command;

class TestButtonFunctionality extends Command
{
    protected $signature = 'test:button-functionality';
    protected $description = 'Test the Sync Extra Fields button functionality';

    public function handle()
    {
        $this->info('🧪 Testing Sync Extra Fields button functionality...');
        $this->newLine();

        // Test 1: Check if job dispatcher can be instantiated
        $this->info('📋 Test 1: Job Dispatcher Instantiation');
        try {
            $job = new DispatchArticleExtraFieldsSyncJobs(5, 1);
            $this->line('  ✅ DispatchArticleExtraFieldsSyncJobs can be instantiated');
        } catch (\Exception $e) {
            $this->line('  ❌ Failed to instantiate DispatchArticleExtraFieldsSyncJobs');
            $this->line('     Error: ' . $e->getMessage());
            return;
        }
        $this->newLine();

        // Test 2: Test manual job dispatch
        $this->info('📋 Test 2: Manual Job Dispatch');
        try {
            $this->line('  🔵 Dispatching test job with 2 articles, 1 second delay...');
            
            DispatchArticleExtraFieldsSyncJobs::dispatch(
                batchSize: 2,
                delaySeconds: 1
            );
            
            $this->line('  ✅ Job dispatched successfully');
            $this->line('  💡 Check queue status: php artisan queue:listen --timeout=10');
        } catch (\Exception $e) {
            $this->line('  ❌ Failed to dispatch job');
            $this->line('     Error: ' . $e->getMessage());
            $this->line('     Trace: ' . $e->getTraceAsString());
        }
        $this->newLine();

        // Test 3: Check service container bindings
        $this->info('📋 Test 3: Service Container Bindings');
        try {
            $fieldMapper = app(\App\Services\Robaws\RobawsFieldMapper::class);
            $this->line('  ✅ RobawsFieldMapper can be resolved from container');
            
            $provider = app(\App\Services\Robaws\RobawsArticleProvider::class);
            $this->line('  ✅ RobawsArticleProvider can be resolved from container');
            
            $enhancementService = app(\App\Services\Robaws\ArticleSyncEnhancementService::class);
            $this->line('  ✅ ArticleSyncEnhancementService can be resolved from container');
            
        } catch (\Exception $e) {
            $this->line('  ❌ Service container binding issue');
            $this->line('     Error: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 4: Check queue configuration
        $this->info('📋 Test 4: Queue Configuration');
        $queueConnection = config('queue.default');
        $this->line("  Queue connection: {$queueConnection}");
        
        $pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $this->line("  Pending jobs: {$pendingJobs}");
        
        $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        $this->line("  Failed jobs: {$failedJobs}");
        $this->newLine();

        $this->info('✅ Button functionality test completed!');
        $this->info('💡 Next: Click the button in admin panel and check logs with:');
        $this->info('   tail -f storage/logs/laravel.log | grep "🔵\\|🔴"');
    }
}
