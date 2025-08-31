<?php

namespace App\Console\Commands\Robaws;

use Illuminate\Console\Command;
use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

class ReformatRecent extends Command
{
    protected $signature = 'robaws:reformat {--limit=50 : Number of recent documents to process} {--force : Skip confirmation prompt}';
    protected $description = 'Re-map and validate recent documents using JSON mapping';

    public function handle(EnhancedRobawsIntegrationService $svc): int
    {
        $limit = (int) $this->option('limit');
        
        if (!$this->option('force')) {
            if (!$this->confirm("This will re-process the {$limit} most recent documents. Continue?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $this->info("Re-formatting {$limit} recent documents using JsonFieldMapper...");
        
        $docs = Document::whereHas('extractions', function ($q) {
            $q->where('status', 'completed');
        })->latest()->limit($limit)->get();
        
        if ($docs->isEmpty()) {
            $this->warn('No documents found with completed extractions.');
            return self::SUCCESS;
        }

        $ok = $fail = 0;
        $bar = $this->output->createProgressBar($docs->count());
        $bar->start();

        foreach ($docs as $doc) {
            try {
                $result = $svc->processDocumentFromExtraction($doc);
                $result ? $ok++ : $fail++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to process document {$doc->id}: " . $e->getMessage());
                $fail++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Completed: {$ok} successful, {$fail} failed");
        
        if ($fail > 0) {
            $this->warn("Some documents failed processing. Check the logs for details.");
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
