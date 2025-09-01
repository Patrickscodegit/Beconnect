<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MigrateDocumentStorage extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'storage:migrate-documents 
                            {--from= : Source storage disk}
                            {--to= : Target storage disk}
                            {--document= : Specific document ID to migrate}
                            {--dry-run : Show what would be migrated without actually doing it}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     */
    protected $description = 'Migrate documents between storage disks (e.g., from spaces to local for development)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fromDisk = $this->option('from');
        $toDisk = $this->option('to');
        $documentId = $this->option('document');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Auto-detect common migration scenarios
        if (!$fromDisk || !$toDisk) {
            $this->detectMigrationScenario($fromDisk, $toDisk);
        }

        if (!$fromDisk || !$toDisk) {
            $this->error('Source and target storage disks must be specified.');
            return 1;
        }

        // Validate storage disks exist
        try {
            Storage::disk($fromDisk);
            Storage::disk($toDisk);
        } catch (\Exception $e) {
            $this->error("Invalid storage disk configuration: {$e->getMessage()}");
            return 1;
        }

        // Get documents to migrate
        $query = Document::where('storage_disk', $fromDisk);
        
        if ($documentId) {
            $query->where('id', $documentId);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->info('No documents found to migrate.');
            return 0;
        }

        $this->info("Found {$documents->count()} document(s) to migrate from '{$fromDisk}' to '{$toDisk}'");

        if ($dryRun) {
            $this->info('DRY RUN - No actual migration will occur');
        }

        // Show what will be migrated
        $this->table(
            ['ID', 'Filename', 'Current Path', 'Size', 'Status'],
            $documents->map(function ($doc) use ($fromDisk) {
                $exists = $this->checkFileExists($fromDisk, $doc->file_path);
                return [
                    $doc->id,
                    $doc->filename,
                    $doc->file_path,
                    $this->formatBytes($doc->size ?? 0),
                    $exists ? '✅ Found' : '❌ Missing'
                ];
            })
        );

        // Confirm migration
        if (!$force && !$dryRun) {
            if (!$this->confirm('Proceed with migration?')) {
                $this->info('Migration cancelled.');
                return 0;
            }
        }

        // Perform migration
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($documents as $document) {
            $result = $this->migrateDocument($document, $fromDisk, $toDisk, $dryRun);
            
            switch ($result) {
                case 'migrated':
                    $migrated++;
                    break;
                case 'skipped':
                    $skipped++;
                    break;
                case 'error':
                    $errors++;
                    break;
            }
        }

        // Summary
        $this->info("\nMigration Summary:");
        $this->info("✅ Migrated: {$migrated}");
        if ($skipped > 0) $this->info("⚠️  Skipped: {$skipped}");
        if ($errors > 0) $this->error("❌ Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Auto-detect common migration scenarios
     */
    private function detectMigrationScenario(?string &$fromDisk, ?string &$toDisk): void
    {
        $environment = app()->environment();
        
        // Find documents with storage mismatches
        $spacesDocs = Document::where('storage_disk', 'spaces')->count();
        $localDocs = Document::where('storage_disk', 'local')->count();
        $documentsDocs = Document::where('storage_disk', 'documents')->count();

        $this->info("Current document distribution:");
        if ($spacesDocs > 0) $this->info("  - 'spaces' disk: {$spacesDocs} documents");
        if ($localDocs > 0) $this->info("  - 'local' disk: {$localDocs} documents");
        if ($documentsDocs > 0) $this->info("  - 'documents' disk: {$documentsDocs} documents");

        // Suggest common scenarios
        if ($environment === 'local' && $spacesDocs > 0) {
            $this->info("\n🔍 Detected: Local environment with DigitalOcean Spaces documents");
            $this->info("💡 Suggestion: Migrate 'spaces' → 'local' for development");
            
            if (!$fromDisk) $fromDisk = 'spaces';
            if (!$toDisk) $toDisk = 'local';
        }

        // Let user choose if not auto-detected
        if (!$fromDisk) {
            $fromDisk = $this->choice('Select source storage disk:', ['spaces', 'local', 'documents', 's3']);
        }
        
        if (!$toDisk) {
            $toDisk = $this->choice('Select target storage disk:', ['local', 'documents', 'spaces', 's3']);
        }
    }

    /**
     * Check if file exists on disk
     */
    private function checkFileExists(string $disk, ?string $path): bool
    {
        if (!$path) return false;
        
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Migrate a single document
     */
    private function migrateDocument(Document $document, string $fromDisk, string $toDisk, bool $dryRun): string
    {
        try {
            // Check if source file exists
            if (!$this->checkFileExists($fromDisk, $document->file_path)) {
                $this->warn("⚠️  Skipping Document {$document->id}: Source file not found");
                return 'skipped';
            }

            if ($dryRun) {
                $this->info("🔄 Would migrate Document {$document->id}: {$document->filename}");
                return 'migrated';
            }

            // Get file content from source
            $content = Storage::disk($fromDisk)->get($document->file_path);
            
            // Generate new path for target disk
            $newPath = $this->generateTargetPath($document, $toDisk);
            
            // Store in target disk
            Storage::disk($toDisk)->put($newPath, $content);
            
            // Update document record
            $document->update([
                'storage_disk' => $toDisk,
                'file_path' => $newPath
            ]);

            $this->info("✅ Migrated Document {$document->id}: {$document->filename}");
            
            Log::info('Document migrated between storage disks', [
                'document_id' => $document->id,
                'filename' => $document->filename,
                'from_disk' => $fromDisk,
                'to_disk' => $toDisk,
                'old_path' => $document->getOriginal('file_path'),
                'new_path' => $newPath
            ]);

            return 'migrated';

        } catch (\Exception $e) {
            $this->error("❌ Error migrating Document {$document->id}: {$e->getMessage()}");
            
            Log::error('Document migration failed', [
                'document_id' => $document->id,
                'from_disk' => $fromDisk,
                'to_disk' => $toDisk,
                'error' => $e->getMessage()
            ]);

            return 'error';
        }
    }

    /**
     * Generate appropriate target path for document
     */
    private function generateTargetPath(Document $document, string $toDisk): string
    {
        $filename = basename($document->file_path);
        
        // Use disk-appropriate path structure
        switch ($toDisk) {
            case 'local':
                return 'documents/' . $filename;
            case 'documents':
                return $filename;
            case 'spaces':
            case 's3':
                return 'documents/' . $filename;
            default:
                return $filename;
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
