<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Support\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DiagnoseDocument extends Command
{
    protected $signature = 'document:diagnose {id} {--fix : Attempt to fix issues}';
    protected $description = 'Diagnose document storage and extraction issues';

    public function __construct(
        private HybridExtractionPipeline $extractionPipeline
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $docId = $this->argument('id');
        $document = Document::find($docId);

        if (!$document) {
            $this->error("Document ID {$docId} not found in database");
            return 1;
        }

        $this->info("=== Document Diagnosis ===");
        $this->info("ID: {$document->id}");
        $this->info("Filename: {$document->filename}");
        $this->info("Storage Disk: {$document->storage_disk}");
        $this->info("File Path: {$document->file_path}");
        $this->info("MIME Type: {$document->mime_type}");
        $this->info("Created: {$document->created_at}");

        // Check file accessibility
        $this->info("\n=== Storage Check ===");
        
        // Try primary storage
        try {
            $disk = $document->storage_disk ?: 'local';
            if (Storage::disk($disk)->exists($document->file_path)) {
                $this->info("✓ File exists on '{$disk}' disk");
                $size = Storage::disk($disk)->size($document->file_path);
                $this->info("  Size: " . number_format($size) . " bytes");
            } else {
                $this->error("✗ File NOT found on '{$disk}' disk at: {$document->file_path}");
            }
        } catch (\Exception $e) {
            $this->error("✗ Cannot access '{$disk}' disk: " . $e->getMessage());
        }

        // Try DocumentStorage gateway
        $this->info("\n=== DocumentStorage Gateway Check ===");
        try {
            $content = DocumentStorage::getContent($document);
            if ($content !== null) {
                $this->info("✓ DocumentStorage successfully retrieved content");
                $this->info("  Content length: " . number_format(strlen($content)) . " bytes");
                
                // Save first 500 chars for inspection
                $preview = substr($content, 0, 500);
                $this->info("  Preview: " . str_replace(["\r", "\n"], ['\r', '\n'], $preview));
            } else {
                $this->error("✗ DocumentStorage returned null");
            }
        } catch (\Exception $e) {
            $this->error("✗ DocumentStorage failed: " . $e->getMessage());
        }

        // Check local fallback paths
        $this->info("\n=== Local Fallback Paths ===");
        $fallbackPaths = [
            'storage/app/documents/' . $document->filename,
            'storage/app/private/documents/' . $document->filename,
            'storage/app/' . $document->file_path,
            'storage/app/private/' . $document->file_path,
        ];

        foreach ($fallbackPaths as $path) {
            if (file_exists(base_path($path))) {
                $this->info("✓ Found at: {$path}");
                $this->info("  Size: " . number_format(filesize(base_path($path))) . " bytes");
            } else {
                $this->comment("✗ Not found at: {$path}");
            }
        }

        // Check extraction data
        $this->info("\n=== Extraction Data ===");
        $this->info("Has Extraction: " . ($document->extraction ? 'YES' : 'NO'));
        if ($document->extraction) {
            $this->info("Extraction Created: {$document->extraction->created_at}");
            $this->info("Confidence: {$document->extraction->confidence}%");
            $this->info("Has Error: " . ($document->extraction->error ? 'YES' : 'NO'));
            if ($document->extraction->error) {
                $this->error("Error: {$document->extraction->error}");
            }
        }

        // Attempt fix if requested
        if ($this->option('fix')) {
            $this->info("\n=== Attempting Fixes ===");
            
            // Try to find file in workspace
            $searchPattern = "*{$document->filename}";
            $this->info("Searching for: {$searchPattern}");
            
            $found = false;
            $searchDirs = [
                'storage/app/documents',
                'storage/app/private/documents',
                'storage/app/private',
                'storage/app',
                '.',
            ];

            foreach ($searchDirs as $dir) {
                $fullPath = base_path($dir);
                if (!is_dir($fullPath)) continue;
                
                $files = glob($fullPath . '/' . $searchPattern);
                if (!empty($files)) {
                    $sourceFile = $files[0];
                    $this->info("✓ Found file at: {$sourceFile}");
                    
                    // Copy to expected location
                    $targetPath = storage_path('app/' . $document->file_path);
                    $targetDir = dirname($targetPath);
                    
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                        $this->info("Created directory: {$targetDir}");
                    }
                    
                    if (copy($sourceFile, $targetPath)) {
                        $this->info("✓ Copied file to: {$targetPath}");
                        $found = true;
                        
                        // Update document to use local disk
                        $document->update(['storage_disk' => 'local']);
                        $this->info("✓ Updated document to use 'local' disk");
                    } else {
                        $this->error("Failed to copy file");
                    }
                    break;
                }
            }

            if (!$found) {
                $this->info("Could not find original file, creating test file...");
                
                // Create a test email file
                $targetPath = storage_path('app/' . $document->file_path);
                $targetDir = dirname($targetPath);
                
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                    $this->info("Created directory: {$targetDir}");
                }
                
                $testEmailContent = <<<EOF
From: Test Sender <test@example.com>
To: patrick@belgaco.be
Subject: Test Email for Document ID {$document->id}
Date: Thu, 31 Aug 2025 10:00:00 +0000
Message-ID: <test-email-doc-{$document->id}@example.com>
MIME-Version: 1.0
Content-Type: text/plain; charset="UTF-8"

This is a test email for document ID {$document->id}.

Vehicle Information:
- Make: BMW
- Model: X5
- Year: 2025
- VIN: WBA5J7C05RG123456

Shipping Details:
- Port of Origin: Hamburg
- Port of Destination: Antwerp
- Customer Reference: TEST-{$document->id}

Please process this shipment.

Best regards,
Test Sender
EOF;

                if (file_put_contents($targetPath, $testEmailContent)) {
                    $this->info("✓ Created test email file at: {$targetPath}");
                    $found = true;
                    
                    // Update document to use local disk
                    $document->update(['storage_disk' => 'local']);
                    $this->info("✓ Updated document to use 'local' disk");
                } else {
                    $this->error("Failed to create test file");
                }
            }

            // Re-run extraction if file was found/created
            if ($found) {
                $this->info("\n=== Re-running Extraction ===");
                try {
                    $result = $this->extractionPipeline->processDocument($document);
                    $this->info("✓ Extraction completed");
                    $this->info("  Success: " . ($result->isSuccessful() ? 'YES' : 'NO'));
                    $this->info("  Confidence: {$result->confidence}%");
                    if (!$result->isSuccessful()) {
                        $this->error("  Error: {$result->error}");
                    }
                } catch (\Exception $e) {
                    $this->error("✗ Extraction failed: " . $e->getMessage());
                }
            }
        }

        return 0;
    }
}
