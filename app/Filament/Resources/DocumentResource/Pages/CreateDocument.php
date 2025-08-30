<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        try {
            // Handle file upload - Filament may provide either a temporary reference or a stored path
            if (isset($data['file_upload'])) {
                \Log::debug('File upload data received', [
                    'file_upload' => $data['file_upload'],
                    'data_type' => gettype($data['file_upload'])
                ]);
                
                // Get the file path/reference from Filament's structure
                $fileReference = null;
                
                if (is_string($data['file_upload'])) {
                    // Simple string format - could be either livewire-file: or direct path
                    $fileReference = $data['file_upload'];
                } elseif (is_array($data['file_upload']) && isset($data['file_upload'][0])) {
                    // Complex array format from Filament
                    $fileData = $data['file_upload'][0];
                    if (is_array($fileData)) {
                        $fileKey = array_key_first($fileData);
                        if ($fileKey && isset($fileData[$fileKey][0])) {
                            $fileReference = $fileData[$fileKey][0];
                        }
                    }
                }
                
                if (!$fileReference) {
                    throw new \Exception('Could not extract file reference from upload data');
                }
                
                \Log::debug('Extracted file reference', ['reference' => $fileReference]);
                
                // Check if this is a Livewire temporary file reference or a direct path
                if (strpos($fileReference, 'livewire-file:') === 0) {
                    // This is a Livewire temporary file reference
                    \Log::debug('Processing Livewire temporary file');
                    
                    $temporaryFile = \Livewire\Features\SupportFileUploads\TemporaryUploadedFile::createFromLivewire($fileReference);
                    
                    // Generate unique filename
                    $originalName = $data['filename'] ?? $temporaryFile->getClientOriginalName();
                    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $filename = Str::ulid() . '.' . $extension;
                    
                    // Get the real path and store the file
                    $tempRealPath = $temporaryFile->getRealPath();
                    
                    if (!file_exists($tempRealPath)) {
                        throw new \Exception('Temporary file not found at: ' . $tempRealPath);
                    }
                    
                    $fileContents = file_get_contents($tempRealPath);
                    
                    if ($fileContents === false) {
                        throw new \Exception('Failed to read temporary file contents');
                    }
                    
                    // Store the file
                    $filePath = 'documents/' . $filename;
                    $stored = Storage::disk(config('filesystems.default'))->put($filePath, $fileContents);
                    
                    if (!$stored) {
                        throw new \Exception('Failed to store file');
                    }
                    
                    // Get file info from temporary file
                    $mimeType = $data['mime_type'] ?? $temporaryFile->getMimeType();
                    $fileSize = $data['file_size'] ?? $temporaryFile->getSize();
                    
                } elseif (preg_match('/^documents\/[A-Z0-9]+\.\w+$/i', $fileReference)) {
                    // This looks like a direct file path that Filament has already stored
                    \Log::debug('File already stored by Filament', ['path' => $fileReference]);
                    
                    // The file is already stored, just use the provided path
                    $filePath = $fileReference;
                    
                    // Extract the original filename from the data or use the stored filename
                    $originalName = $data['filename'] ?? basename($fileReference);
                    
                    // Get file info from the stored file
                    $storageDisk = Storage::disk(config('filesystems.default'));
                    
                    if (!$storageDisk->exists($filePath)) {
                        throw new \Exception('Stored file not found at: ' . $filePath);
                    }
                    
                    $mimeType = $data['mime_type'] ?? $storageDisk->mimeType($filePath);
                    $fileSize = $data['file_size'] ?? $storageDisk->size($filePath);
                    
                } else {
                    // Unexpected format
                    throw new \Exception('Unexpected file reference format: ' . $fileReference);
                }
                
                // Update data array with file information
                $data['file_path'] = $filePath;
                $data['filename'] = $originalName;
                $data['mime_type'] = $mimeType;
                $data['file_size'] = $fileSize;
                $data['storage_disk'] = config('filesystems.default');
                
                \Log::info('File upload processed successfully', [
                    'file_path' => $filePath,
                    'filename' => $originalName,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize
                ]);
                
                // Remove the temporary file reference
                unset($data['file_upload']);
            }
        } catch (\Exception $e) {
            \Log::error('File upload processing failed', [
                'error' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            
            Notification::make()
                ->title('File upload failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
                
            throw $e;
        }
        
        return $data;
    }
    
    protected function afterCreate(): void
    {
        try {
            // Dispatch extraction job for standalone documents (without intake_id)
            if (!$this->record->intake_id) {
                ExtractDocumentData::dispatch($this->record);
                
                Notification::make()
                    ->title('Document created')
                    ->body('Processing has been started for your document.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Document created')
                    ->body('Document has been attached to the intake.')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            \Log::error('Failed to dispatch extraction job', [
                'document_id' => $this->record->id,
                'error' => $e->getMessage()
            ]);
            
            Notification::make()
                ->title('Processing failed')
                ->body('Document was created but processing could not be started.')
                ->warning()
                ->send();
        }
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
