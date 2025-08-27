<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestWhatsAppExtraction extends Command
{
    protected $signature = 'test:whatsapp-extraction {document_id?}';
    protected $description = 'Test WhatsApp screenshot extraction';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        
        // If no ID provided, find the latest WhatsApp/screenshot image
        if (!$documentId) {
            $document = Document::where('mime_type', 'like', 'image/%')
                ->where(function ($query) {
                    $query->where('filename', 'like', '%whatsapp%')
                        ->orWhere('filename', 'like', '%screenshot%')
                        ->orWhere('filename', 'like', 'IMG_%');
                })
                ->latest()
                ->first();
            
            if (!$document) {
                // Find any recent image
                $document = Document::where('mime_type', 'like', 'image/%')
                    ->latest()
                    ->first();
            }
        } else {
            $document = Document::find($documentId);
        }
        
        if (!$document) {
            $this->error('No suitable document found.');
            return 1;
        }
        
        $this->info("Testing extraction for Document ID: {$document->id}");
        $this->line("Filename: {$document->filename}");
        $this->line("File path: {$document->file_path}");
        $this->line("MIME type: {$document->mime_type}");
        
        // Check if file exists
        $disk = Storage::disk(config('filesystems.default', 'local'));
        if (!$disk->exists($document->file_path)) {
            $this->error("File not found in storage: {$document->file_path}");
            return 1;
        }
        
        $this->info('File exists in storage. Dispatching extraction job...');
        
        // Dispatch the job synchronously for immediate feedback
        try {
            dispatch_sync(new ExtractDocumentData($document));
            
            // Refresh and show results
            $document->refresh();
            $extraction = $document->extractions()->latest()->first();
            
            if ($extraction) {
                $this->info("✅ Extraction completed!");
                $this->line("Status: {$extraction->status}");
                $this->line("Service: {$extraction->service_used}");
                $this->line("Confidence: " . ($extraction->confidence * 100) . "%");
                
                if ($extraction->extracted_data) {
                    $this->line("\nExtracted Data:");
                    $this->line(json_encode($extraction->extracted_data, JSON_PRETTY_PRINT));
                }
            } else {
                $this->error("No extraction record found.");
            }
            
        } catch (\Exception $e) {
            $this->error("Extraction failed: " . $e->getMessage());
            $this->line("Trace: " . $e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}
