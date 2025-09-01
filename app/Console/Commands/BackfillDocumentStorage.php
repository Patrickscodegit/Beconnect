<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentStorageConfig;
use Illuminate\Console\Command;

class BackfillDocumentStorage extends Command
{
    protected $signature = 'documents:backfill-storage {--dry-run : Show what would be changed without making changes}';
    protected $description = 'Normalize storage_disk and file_path for documents';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $count = 0;
        $changes = [];
        
        $this->info('Scanning documents for storage normalization...');
        
        Document::query()
            ->whereNull('storage_disk')
            ->orWhere('storage_disk', '')
            ->orWhere('file_path', '')
            ->chunkById(500, function ($documents) use (&$count, &$changes, $dryRun) {
                foreach ($documents as $doc) {
                    $changeset = [];
                    
                    // Check storage disk
                    if (empty($doc->storage_disk)) {
                        $newDisk = DocumentStorageConfig::getStorageDisk();
                        $changeset['storage_disk'] = [
                            'from' => $doc->storage_disk ?: 'null',
                            'to' => $newDisk
                        ];
                        if (!$dryRun) {
                            $doc->storage_disk = $newDisk;
                        }
                    }
                    
                    // Check file path
                    if ($doc->filename && empty($doc->file_path)) {
                        $newPath = DocumentStorageConfig::getDocumentPath($doc->filename);
                        $changeset['file_path'] = [
                            'from' => $doc->file_path ?: 'null',
                            'to' => $newPath
                        ];
                        if (!$dryRun) {
                            $doc->file_path = $newPath;
                        }
                    }
                    
                    if (!empty($changeset)) {
                        $changes[$doc->id] = $changeset;
                        if (!$dryRun) {
                            $doc->saveQuietly();
                        }
                        $count++;
                    }
                }
            });
        
        if ($dryRun) {
            $this->info("Would update {$count} documents:");
            foreach ($changes as $docId => $changeset) {
                $this->line("Document #{$docId}:");
                foreach ($changeset as $field => $change) {
                    $this->line("  {$field}: {$change['from']} → {$change['to']}");
                }
            }
        } else {
            $this->info("✅ Backfilled {$count} documents.");
        }
        
        return self::SUCCESS;
    }
}
