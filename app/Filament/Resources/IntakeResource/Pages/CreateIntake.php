<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use App\Models\Intake;
use App\Models\Document;
use App\Services\DocumentService;
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
        // Extract uploaded files before creating the intake
        $uploadedFiles = $data['document_files'] ?? [];
        unset($data['document_files']); // Remove from intake data
        
        // Create the intake record
        $intake = Intake::create($data);
        
        // Process uploaded documents
        if (!empty($uploadedFiles)) {
            $this->processUploadedFiles($intake, $uploadedFiles);
        }
        
        return $intake;
    }
    
    private function processUploadedFiles(Intake $intake, array $files): void
    {
        $processedCount = 0;
        $failedCount = 0;
        
        foreach ($files as $file) {
            try {
                if (is_string($file)) {
                    // $file is a path to the temporary file uploaded by Filament
                    $tempPath = Storage::disk('local')->path('temp-uploads/' . $file);
                    if (!file_exists($tempPath)) {
                        // Try livewire tmp path
                        $tempPath = storage_path('app/livewire-tmp/' . $file);
                    }
                    
                    if (file_exists($tempPath)) {
                        // Get file info
                        $originalName = basename($file);
                        $mimeType = mime_content_type($tempPath);
                        $fileSize = filesize($tempPath);
                        
                        // Move file to MinIO S3 storage
                        $fileContent = file_get_contents($tempPath);
                        $storagePath = 'documents/' . uniqid() . '_' . $originalName;
                        Storage::disk('minio')->put($storagePath, $fileContent);
                        
                        // Create document record
                        $document = Document::create([
                            'intake_id' => $intake->id,
                            'filename' => $originalName,
                            'file_path' => $storagePath,
                            'mime_type' => $mimeType,
                            'file_size' => $fileSize,
                            'document_type' => 'freight_document',
                            'has_text_layer' => false, // Will be determined during processing
                        ]);
                        
                        // Clean up temporary file
                        unlink($tempPath);
                        $processedCount++;
                        
                        \Log::info('Document uploaded successfully', [
                            'intake_id' => $intake->id,
                            'document_id' => $document->id,
                            'filename' => $originalName
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Failed to process uploaded file', [
                    'intake_id' => $intake->id,
                    'file' => $file,
                    'error' => $e->getMessage()
                ]);
                $failedCount++;
                continue;
            }
        }
        
        // Update intake status if documents were processed
        if ($processedCount > 0) {
            $intake->update(['status' => 'processing']);
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
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
