<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DocumentsMirror extends Command
{
    protected $signature = 'documents:mirror 
                            {id : Document ID to mirror}
                            {--from=spaces : Source storage disk}
                            {--to=local : Target storage disk}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Copy a document file across disks and update its database record.';

    public function handle()
    {
        $documentId = $this->argument('id');
        $fromDisk = $this->option('from');
        $toDisk = $this->option('to');
        $dryRun = $this->option('dry-run');

        try {
            $doc = Document::findOrFail($documentId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->error("Document with ID {$documentId} not found.");
            return 1;
        }

        $this->info("Document Mirror Operation");
        $this->line("Document ID: {$doc->id}");
        $this->line("Filename: {$doc->filename}");
        $this->line("Current disk: {$doc->storage_disk}");
        $this->line("Current path: {$doc->file_path}");
        $this->line("Mirror: {$fromDisk} → {$toDisk}");

        if ($dryRun) {
            $this->warn("DRY RUN - No actual changes will be made");
        }

        // Validate source disk has the file
        try {
            if (!Storage::disk($fromDisk)->exists($doc->file_path)) {
                $this->error("Source file not found on '{$fromDisk}' disk at path: {$doc->file_path}");
                return 1;
            }

            $fileSize = Storage::disk($fromDisk)->size($doc->file_path);
            $this->line("Source file size: " . $this->formatBytes($fileSize));

        } catch (\Exception $e) {
            $this->error("Failed to access source file: {$e->getMessage()}");
            return 1;
        }

        // Confirm operation
        if (!$dryRun && !$this->confirm("Proceed with mirroring?")) {
            $this->info("Operation cancelled.");
            return 0;
        }

        if ($dryRun) {
            $this->info("✓ Would copy file from {$fromDisk} to {$toDisk}");
            $this->info("✓ Would update document record to use '{$toDisk}' disk");
            return 0;
        }

        // Perform the mirror operation
        try {
            $this->line("Reading file from {$fromDisk}...");
            $bytes = Storage::disk($fromDisk)->get($doc->file_path);

            $this->line("Writing file to {$toDisk}...");
            Storage::disk($toDisk)->put($doc->file_path, $bytes, ['visibility' => 'private']);

            // Verify the copy
            if (!Storage::disk($toDisk)->exists($doc->file_path)) {
                throw new \RuntimeException("File copy verification failed");
            }

            $targetSize = Storage::disk($toDisk)->size($doc->file_path);
            if ($targetSize !== strlen($bytes)) {
                throw new \RuntimeException("File size mismatch after copy");
            }

            $this->line("Updating document record...");
            $doc->update(['storage_disk' => $toDisk]);

            $this->info("✓ Successfully mirrored document!");
            $this->line("  File copied: " . $this->formatBytes($targetSize));
            $this->line("  Database updated: storage_disk = '{$toDisk}'");

            Log::info('Document mirrored via artisan command', [
                'document_id' => $doc->id,
                'from_disk' => $fromDisk,
                'to_disk' => $toDisk,
                'file_path' => $doc->file_path,
                'file_size' => $targetSize
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error("Mirror operation failed: {$e->getMessage()}");
            
            Log::error('Document mirror command failed', [
                'document_id' => $doc->id,
                'from_disk' => $fromDisk,
                'to_disk' => $toDisk,
                'error' => $e->getMessage()
            ]);

            return 1;
        }
    }

    /**
     * Format bytes for human readability
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
