<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentService;
use App\Models\Document;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;
    
    protected function handleRecordCreation(array $data): Document
    {
        // Handle file upload
        if (isset($data['file_upload'])) {
            $uploadedFile = $data['file_upload'];
            $filename = $data['filename'];
            
            // Store file in MinIO
            $filePath = Storage::disk('minio')->putFileAs(
                'documents',
                $uploadedFile,
                $filename
            );
            
            // Create document record
            $document = Document::create([
                'intake_id' => $data['intake_id'],
                'filename' => $filename,
                'file_path' => $filePath,
                'mime_type' => $data['mime_type'],
                'file_size' => $data['file_size'],
            ]);
            
            // Process document asynchronously
            try {
                $documentService = app(DocumentService::class);
                $documentService->processDocument($filePath, $data['intake_id']);
                
                Notification::make()
                    ->title('Document uploaded successfully')
                    ->body('Document processing has been queued.')
                    ->success()
                    ->send();
            } catch (\Exception $e) {
                Notification::make()
                    ->title('Upload successful, but processing failed')
                    ->body('The document was uploaded but could not be processed: ' . $e->getMessage())
                    ->warning()
                    ->send();
            }
            
            return $document;
        }
        
        // Fallback for manual creation
        return parent::handleRecordCreation($data);
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
