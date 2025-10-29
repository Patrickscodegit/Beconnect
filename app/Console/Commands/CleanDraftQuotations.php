<?php

namespace App\Console\Commands;

use App\Models\QuotationRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanDraftQuotations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quotations:clean-drafts 
                          {--days=7 : Delete drafts older than this many days}
                          {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old draft quotations that were never submitted';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $this->info("🧹 Cleaning draft quotations older than {$days} days...");
        $this->newLine();
        
        $cutoffDate = now()->subDays($days);
        
        $query = QuotationRequest::where('status', 'draft')
            ->where('created_at', '<', $cutoffDate);
        
        $count = $query->count();
        
        if ($count === 0) {
            $this->info('✅ No old draft quotations to clean up.');
            return Command::SUCCESS;
        }
        
        if ($dryRun) {
            $this->warn("🔍 DRY RUN MODE - Would delete {$count} draft quotations:");
            $this->newLine();
            
            $drafts = $query->get(['id', 'request_number', 'contact_email', 'created_at']);
            
            $this->table(
                ['ID', 'Request Number', 'Customer Email', 'Created At', 'Age (days)'],
                $drafts->map(function ($draft) {
                    return [
                        $draft->id,
                        $draft->request_number ?: 'N/A',
                        $draft->contact_email,
                        $draft->created_at->format('Y-m-d H:i:s'),
                        $draft->created_at->diffInDays(now()) . ' days',
                    ];
                })
            );
            
            $this->newLine();
            $this->info('Run without --dry-run to actually delete these drafts.');
            
            return Command::SUCCESS;
        }
        
        // Get some sample data for logging
        $sampleDrafts = $query->limit(5)->get(['id', 'contact_email', 'created_at']);
        
        // Delete the drafts
        $deleted = $query->delete();
        
        $this->info("✅ Deleted {$deleted} draft quotations");
        $this->newLine();
        
        // Log the cleanup
        Log::info('Draft quotations cleanup completed', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
            'days' => $days,
            'sample_drafts' => $sampleDrafts->map(fn($d) => [
                'id' => $d->id,
                'email' => $d->contact_email,
                'age_days' => $d->created_at->diffInDays(now()),
            ])->toArray(),
        ]);
        
        return Command::SUCCESS;
    }
}
