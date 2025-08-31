<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use App\Models\Intake;
use App\Models\Document;
use App\Services\DocumentService;
use App\Services\EmailDocumentService;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class CreateIntake extends CreateRecord
{
    protected static string $resource = IntakeResource::class;
    
    protected function handleRecordCreation(array $data): Intake
    {
        \Log::info('CreateIntake: Starting record creation', [
            'data_keys' => array_keys($data),
            'has_document_files' => isset($data['document_files']),
            'document_files_count' => isset($data['document_files']) ? count($data['document_files']) : 0
        ]);
        
        // Extract uploaded files before creating the intake
        $uploadedFiles = $data['document_files'] ?? [];
        unset($data['document_files']); // Remove from intake data
        
        \Log::info('CreateIntake: Extracted files', [
            'uploaded_files' => $uploadedFiles,
            'count' => count($uploadedFiles)
        ]);
        
        // Create the intake record
        $intake = Intake::create($data);
        \Log::info('CreateIntake: Intake created', ['intake_id' => $intake->id]);
        
        // Process uploaded documents
        if (!empty($uploadedFiles)) {
            \Log::info('CreateIntake: Processing uploaded files', ['count' => count($uploadedFiles)]);
            $this->processUploadedFiles($intake, $uploadedFiles);
        } else {
            \Log::info('CreateIntake: No files to process');
        }
        
        return $intake;
    }
    
    private function processUploadedFiles(Intake $intake, array $files): void
    {
        $processedCount = 0;
        $failedCount = 0;
        
        \Log::info('ProcessUploadedFiles: Starting processing', [
            'intake_id' => $intake->id,
            'files' => $files
        ]);
        
        foreach ($files as $file) {
            try {
                \Log::info('ProcessUploadedFiles: Processing file', ['file' => $file]);
                
                if (is_string($file)) {
                    // Remove directory prefix if it exists (Filament adds it)
                    $cleanFileName = str_replace('temp-uploads/', '', $file);
                    
                    // Try multiple possible paths for Filament/Livewire temporary files
                    $possiblePaths = [
                        Storage::disk('local')->path('temp-uploads/' . $cleanFileName),
                        Storage::disk('local')->path($cleanFileName),
                        storage_path('app/livewire-tmp/' . $cleanFileName),
                        storage_path('app/private/temp-uploads/' . $cleanFileName),
                        storage_path('app/private/livewire-tmp/' . $cleanFileName),
                        $file // Direct path
                    ];
                    
                    $tempPath = null;
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path)) {
                            $tempPath = $path;
                            \Log::info('ProcessUploadedFiles: Found file at path', ['path' => $path]);
                            break;
                        }
                    }
                    
                    if (!$tempPath) {
                        \Log::error('ProcessUploadedFiles: File not found in any path', [
                            'file' => $file,
                            'checked_paths' => $possiblePaths
                        ]);
                        $failedCount++;
                        continue;
                    }
                    
                    // Get file info
                    $originalName = basename($cleanFileName);
                    $mimeType = mime_content_type($tempPath);
                    $fileSize = filesize($tempPath);
                    
                    \Log::info('ProcessUploadedFiles: File info', [
                        'original_name' => $originalName,
                        'mime_type' => $mimeType,
                        'file_size' => $fileSize
                    ]);
                    
                    // Try to store in Spaces, fallback to local if Spaces is down
                    $fileContent = file_get_contents($tempPath);
                    $storagePath = 'documents/' . uniqid() . '_' . $originalName;
                    
                    try {
                        // Try DigitalOcean Spaces first
                        Storage::disk('spaces')->put($storagePath, $fileContent);
                        $storageDisk = 'spaces';
                        \Log::info('ProcessUploadedFiles: Stored in DigitalOcean Spaces', ['path' => $storagePath]);
                    } catch (\Exception $e) {
                        // Fallback to local storage
                        Storage::disk('local')->put($storagePath, $fileContent);
                        $storageDisk = 'local';
                        \Log::warning('ProcessUploadedFiles: Spaces failed, using local storage', [
                            'error' => $e->getMessage(),
                            'path' => $storagePath
                        ]);
                    }
                    
                    // Check if this is an email file and use EmailDocumentService for deduplication
                    if (str_ends_with(strtolower($originalName), '.eml')) {
                        try {
                            $emailService = app(EmailDocumentService::class);
                            $result = $emailService->ingestStoredEmail($storageDisk, $storagePath, $intake->id, $originalName);
                            
                            if ($result['skipped_as_duplicate']) {
                                \Log::info('ProcessUploadedFiles: Email duplicate detected, cleaning up storage', [
                                    'filename' => $originalName,
                                    'existing_document_id' => $result['document_id'] ?? null,
                                    'storage_path' => $storagePath,
                                    'storage_disk' => $storageDisk
                                ]);
                                
                                // Clean up the duplicate file from storage
                                try {
                                    Storage::disk($storageDisk)->delete($storagePath);
                                    \Log::info('ProcessUploadedFiles: Duplicate file cleaned from storage', [
                                        'path' => $storagePath,
                                        'disk' => $storageDisk
                                    ]);
                                } catch (\Exception $cleanupError) {
                                    \Log::error('ProcessUploadedFiles: Failed to cleanup duplicate file', [
                                        'error' => $cleanupError->getMessage(),
                                        'path' => $storagePath
                                    ]);
                                }
                                
                                unlink($tempPath);
                                continue; // Skip processing this duplicate
                            }
                            
                            \Log::info('ProcessUploadedFiles: Email document created with deduplication', [
                                'document_id' => $result['document']->id,
                                'storage_disk' => $storageDisk,
                                'fingerprint_type' => isset($result['fingerprint']['message_id']) ? 'message-id' : 'content-hash',
                                'extraction_completed' => !empty($result['extraction_data'])
                            ]);
                            
                        } catch (\Exception $e) {
                            \Log::error('ProcessUploadedFiles: Email ingestion failed, falling back to standard creation', [
                                'error' => $e->getMessage(),
                                'filename' => $originalName,
                                'trace' => $e->getTraceAsString()
                            ]);
                            
                            // Create standard document record as fallback
                            $document = Document::create([
                                'intake_id' => $intake->id,
                                'filename' => $originalName,
                                'file_path' => $storagePath,
                                'mime_type' => $mimeType,
                                'file_size' => $fileSize,
                                'document_type' => 'freight_document',
                                'has_text_layer' => false,
                                'storage_disk' => $storageDisk,
                                'processing_status' => 'failed',
                            ]);
                            
                            \Log::info('ProcessUploadedFiles: Fallback document created', [
                                'document_id' => $document->id,
                                'storage_disk' => $storageDisk
                            ]);
                        }
                    } else {
                        // Create document record for non-email files
                        $document = Document::create([
                            'intake_id' => $intake->id,
                            'filename' => $originalName,
                            'file_path' => $storagePath,
                            'mime_type' => $mimeType,
                            'file_size' => $fileSize,
                            'document_type' => 'freight_document',
                            'has_text_layer' => false, // Will be determined during processing
                            'storage_disk' => $storageDisk,
                        ]);
                        
                        \Log::info('ProcessUploadedFiles: Standard document created', [
                            'document_id' => $document->id,
                            'storage_disk' => $storageDisk
                        ]);
                    }
                    
                    // Clean up temporary file
                    unlink($tempPath);
                    $processedCount++;
                }
            } catch (\Exception $e) {
                \Log::error('ProcessUploadedFiles: Failed to process file', [
                    'intake_id' => $intake->id,
                    'file' => $file,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $failedCount++;
                continue;
            }
        }
        
        // Update intake status if documents were processed
        if ($processedCount > 0) {
            $intake->update(['status' => 'processing']);
            \Log::info('ProcessUploadedFiles: Updated intake status to processing', ['intake_id' => $intake->id]);
        }
        
        // Show notification about upload results
        if ($processedCount > 0) {
            Notification::make()
                ->title("Intake created successfully")
                ->body("{$processedCount} document(s) uploaded and stored" . 
                      ($failedCount > 0 ? ". {$failedCount} file(s) failed to process." : "."))
                ->success()
                ->send();
        } elseif ($failedCount > 0) {
            Notification::make()
                ->title("Intake created with errors")
                ->body("All {$failedCount} uploaded file(s) failed to process.")
                ->warning()
                ->send();
        }
        
        \Log::info('ProcessUploadedFiles: Completed', [
            'intake_id' => $intake->id,
            'processed' => $processedCount,
            'failed' => $failedCount
        ]);
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
