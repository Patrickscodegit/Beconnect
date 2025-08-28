<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Document;
use App\Services\SimpleRobawsIntegration;

class ProcessRobawsMapping extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:process-mapping {--force : Process all documents even if they already have Robaws data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process Robaws mapping for documents with extraction data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing Robaws mapping for extracted documents...');
        
        $service = new SimpleRobawsIntegration();
        
        // Get documents that need processing
        $query = Document::whereNotNull('extraction_data')
                         ->where('extraction_status', 'completed');
        
        if (!$this->option('force')) {
            $query->whereNull('robaws_quotation_data');
        }
        
        $documents = $query->get();
        
        if ($documents->isEmpty()) {
            $this->info('No documents need Robaws mapping processing.');
            return;
        }
        
        $this->info("Found {$documents->count()} documents to process...");
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($documents as $document) {
            $this->line("Processing document {$document->id}: {$document->filename}");
            
            try {
                $result = $service->storeExtractedDataForRobaws($document, $document->extraction_data);
                
                if ($result) {
                    $this->info("  ✓ Successfully processed");
                    $successCount++;
                } else {
                    $this->error("  ✗ Failed to process");
                    $failCount++;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Error: " . $e->getMessage());
                $failCount++;
            }
        }
        
        $this->newLine();
        $this->info("Processing complete:");
        $this->info("  Successful: {$successCount}");
        $this->info("  Failed: {$failCount}");
        
        // Show summary
        $totalWithRobaws = Document::whereNotNull('robaws_quotation_data')->count();
        $readyForExport = $service->getDocumentsReadyForExport()->count();
        
        $this->newLine();
        $this->info("Current status:");
        $this->info("  Documents with Robaws data: {$totalWithRobaws}");
        $this->info("  Documents ready for export: {$readyForExport}");
    }
}
