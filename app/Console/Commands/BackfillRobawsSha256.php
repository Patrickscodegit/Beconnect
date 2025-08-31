<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RobawsDocument;
use App\Support\Files;
use App\Support\StreamHasher;

class BackfillRobawsSha256 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:backfill-sha256 {--dry-run : Show what would be updated without making changes} {--chunk=50 : Number of records to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing SHA-256 values for existing RobawsDocument records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        
        $this->info("🔍 Finding RobawsDocument records missing SHA-256...");
        
        $missingCount = RobawsDocument::whereNull('sha256')->count();
        
        if ($missingCount === 0) {
            $this->info("✅ All RobawsDocument records already have SHA-256 values!");
            return 0;
        }
        
        $this->info("📊 Found {$missingCount} records missing SHA-256");
        
        if ($dryRun) {
            $this->warn("🏃 DRY RUN MODE - No changes will be made");
        }
        
        if (!$dryRun && !$this->confirm("Proceed with backfilling SHA-256 for {$missingCount} records?")) {
            $this->info("❌ Operation cancelled");
            return 1;
        }
        
        $processed = 0;
        $updated = 0;
        $errors = 0;
        
        $progressBar = $this->output->createProgressBar($missingCount);
        $progressBar->start();
        
        RobawsDocument::whereNull('sha256')
            ->chunk($chunkSize, function ($documents) use (&$processed, &$updated, &$errors, $dryRun, $progressBar) {
                foreach ($documents as $document) {
                    $processed++;
                    
                    try {
                        // Try to find the original file and compute SHA-256
                        $sha256 = $this->computeSha256ForDocument($document);
                        
                        if ($sha256) {
                            if (!$dryRun) {
                                $document->update(['sha256' => $sha256]);
                            }
                            $updated++;
                        } else {
                            $errors++;
                        }
                        
                    } catch (\Exception $e) {
                        $this->error("\n❌ Error processing document {$document->id}: " . $e->getMessage());
                        $errors++;
                    }
                    
                    $progressBar->advance();
                }
            });
        
        $progressBar->finish();
        $this->newLine(2);
        
        // Summary
        $this->info("📈 Backfill Summary:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Records Processed', $processed],
                ['Successfully Updated', $updated],
                ['Errors', $errors],
                ['Mode', $dryRun ? 'DRY RUN' : 'LIVE'],
            ]
        );
        
        if ($dryRun && $updated > 0) {
            $this->info("💡 Run without --dry-run to apply these changes");
        }
        
        return $errors > 0 ? 1 : 0;
    }
    
    /**
     * Compute SHA-256 for a RobawsDocument by finding its original file
     */
    private function computeSha256ForDocument(RobawsDocument $document): ?string
    {
        // Try to find the file using various strategies
        $candidates = [
            $document->path ?? null,
            "documents/{$document->filename}",
            $document->filename,
        ];
        
        foreach ($candidates as $path) {
            if (!$path) continue;
            
            try {
                $doc = Files::openDocumentStream($path, ['documents', 'local', 's3']);
                $hashed = StreamHasher::toTempHashedStream($doc['stream']);
                fclose($doc['stream']);
                fclose($hashed['stream']);
                
                return $hashed['sha256'];
                
            } catch (\Exception $e) {
                // Try next candidate
                continue;
            }
        }
        
        $this->warn("⚠️  Could not find file for RobawsDocument {$document->id} (filename: {$document->filename})");
        return null;
    }
}
