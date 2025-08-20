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
        $documentService = app(DocumentService::class);
        $processedCount = 0;
        $failedCount = 0;
        
        foreach ($files as $file) {
            try {
                if ($file instanceof UploadedFile || is_string($file)) {
                    // Get the actual file from temporary storage
                    if (is_string($file)) {
                        // $file is a path to the temporary file
                        $tempPath = Storage::disk('local')->path('livewire-tmp/' . $file);
                        if (!file_exists($tempPath)) {
                            // Try the direct path
                            $tempPath = storage_path('app/livewire-tmp/' . $file);
                        }
                        
                        if (file_exists($tempPath)) {
                            $uploadedFile = new UploadedFile(
                                $tempPath,
                                basename($file),
                                mime_content_type($tempPath),
                                null,
                                true
                            );
                        } else {
                            continue; // Skip if file not found
                        }
                    } else {
                        $uploadedFile = $file;
                    }
                    
                    // Store file in MinIO
                    $filePath = Storage::disk('minio')->putFile('documents', $uploadedFile);
                    
                    // Create document record
                    $document = Document::create([
                        'intake_id' => $intake->id,
                        'filename' => $uploadedFile->getClientOriginalName(),
                        'file_path' => $filePath,
                        'mime_type' => $uploadedFile->getClientMimeType(),
                        'file_size' => $uploadedFile->getSize(),
                        'document_type' => 'unknown', // Will be classified during processing
                    ]);
                    
                    // Queue document for processing
                    try {
                        $documentService->processDocument($filePath, $intake->id);
                        $processedCount++;
                    } catch (\Exception $e) {
                        // Document created but processing failed
                        $failedCount++;
                    }
                }
            } catch (\Exception $e) {
                $failedCount++;
                continue;
            }
        }
        
        // Show notification about upload results
        if ($processedCount > 0) {
            Notification::make()
                ->title("Intake created successfully")
                ->body("{$processedCount} document(s) uploaded and queued for processing" . 
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
