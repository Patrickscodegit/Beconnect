<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Support\EmailFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BackfillEmailFingerprints extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:backfill-fingerprints 
                            {--chunk=50 : Number of documents to process per chunk}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill email fingerprints for existing documents missing source_message_id or source_content_sha';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        $this->info('Starting email fingerprint backfill...');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Query documents that need fingerprinting
        $query = Document::query()
            ->whereNull('source_content_sha')
            ->where(function($q) {
                $q->where('mime_type', 'message/rfc822')
                  ->orWhere('filename', 'like', '%.eml')
                  ->orWhere('file_path', 'like', '%.eml');
            });

        $totalCount = $query->count();
        $this->info("Found {$totalCount} documents needing fingerprint backfill");

        if ($totalCount === 0) {
            $this->info('No documents need fingerprint backfill');
            return 0;
        }

        $processedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        $query->chunkById($chunkSize, function($documents) use (&$processedCount, &$updatedCount, &$skippedCount, &$errorCount, $dryRun, $progressBar) {
            foreach ($documents as $document) {
                $processedCount++;
                
                try {
                    // Check if file exists
                    $disk = Storage::disk($document->storage_disk ?: 'local');
                    if (!$disk->exists($document->file_path)) {
                        $this->newLine();
                        $this->error("File not found for document {$document->id}: {$document->file_path}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Read and fingerprint email
                    $rawEmail = $disk->get($document->file_path);
                    $headers = EmailFingerprint::parseHeaders($rawEmail);
                    $plainBody = EmailFingerprint::extractPlainBody($rawEmail);
                    $fingerprint = EmailFingerprint::fromRaw($rawEmail, $headers, $plainBody);

                    if (!$dryRun) {
                        // Update document with fingerprint
                        $document->update([
                            'source_message_id' => $fingerprint['message_id'],
                            'source_content_sha' => $fingerprint['content_sha'],
                            'processing_status' => $document->processing_status ?: 'pending',
                        ]);
                    }

                    $updatedCount++;
                    
                    if ($this->getOutput()->isVerbose()) {
                        $this->newLine();
                        $this->line("Updated document {$document->id}: {$document->filename}");
                        $this->line("  Message-ID: " . ($fingerprint['message_id'] ?: 'none'));
                        $this->line("  Content SHA: " . substr($fingerprint['content_sha'], 0, 16) . '...');
                    }

                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("Error processing document {$document->id}: " . $e->getMessage());
                    
                    Log::error('BackfillEmailFingerprints: Processing failed', [
                        'document_id' => $document->id,
                        'error' => $e->getMessage(),
                        'file_path' => $document->file_path,
                    ]);
                    
                    $errorCount++;
                }
                
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Backfill completed!');
        $this->table(['Metric', 'Count'], [
            ['Total processed', $processedCount],
            ['Successfully updated', $updatedCount],
            ['Skipped (file not found)', $skippedCount],
            ['Errors', $errorCount],
        ]);

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No actual changes were made');
            $this->info('Run without --dry-run to apply the changes');
        }

        return $errorCount > 0 ? 1 : 0;
    }
}
