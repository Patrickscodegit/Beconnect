<?php

namespace App\Providers;

use App\Models\Document;
use App\Services\DocumentStorageConfig;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class DocumentStorageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        Document::creating(function (Document $document) {
            // Only override if blank - don't override 'documents' disk
            if (blank($document->storage_disk)) {
                $disk = DocumentStorageConfig::getStorageDisk();
                $document->storage_disk = $disk;
                
                DocumentStorageConfig::logStorageSelection('document_creating', $disk);
            }
            
            // Normalize file path
            if (!empty($document->filename) && blank($document->file_path)) {
                $document->file_path = DocumentStorageConfig::getDocumentPath($document->filename);
                
                Log::info('Set document file path', [
                    'filename' => $document->filename,
                    'file_path' => $document->file_path,
                ]);
            }
        });
    }
}
