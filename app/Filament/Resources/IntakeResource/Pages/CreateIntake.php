<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use App\Models\Intake;
use App\Services\IntakeCreationService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreateIntake extends CreateRecord
{
    protected static string $resource = IntakeResource::class;
    
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Auto-set status and source (hidden from form)
        $data['status'] = 'pending';
        $data['source'] = 'manual_upload';
        
        return $data;
    }
    
    protected function handleRecordCreation(array $data): Intake
    {
        \Log::info('CreateIntake: Starting record creation with robust file handling', [
            'data_keys' => array_keys($data),
            'has_intake_files' => isset($data['intake_files']),
        ]);
        
        /** @var \App\Services\IntakeCreationService $intakeCreationService */
        $intakeCreationService = app(\App\Services\IntakeCreationService::class);

        // 1) Fetch raw state (not the dehydrated/normalized one) 
        $raw = $this->form->getRawState()['intake_files'] ?? [];

        // 2) Flatten & convert to UploadedFile[]
        $uploadedFiles = collect(is_array($raw) ? $raw : [$raw])
            ->flatMap(function ($item) {
                // Livewire sometimes wraps each file in an associative array keyed by a UUID
                if ($item instanceof TemporaryUploadedFile || $item instanceof UploadedFile || is_string($item)) {
                    return [$item];
                }
                if (is_array($item)) {
                    // e.g. [ 'uuid' => TemporaryUploadedFile, ... ] or [TemporaryUploadedFile, ...]
                    return array_values($item);
                }
                return [];
            })
            ->map(function($f) {
                try {
                    return $this->convertToUploadedFile($f);
                } catch (\Throwable $e) {
                    \Log::warning('File convert failed', ['error' => $e->getMessage(), 'file_type' => gettype($f)]);
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();

        if (empty($uploadedFiles)) {
            \Filament\Notifications\Notification::make()
                ->title('No files received')
                ->body('Please re-select your files and try again.')
                ->danger()
                ->send();
            
            // Create simple intake without files as fallback
            $intake = Intake::create([
                'status' => $data['status'] ?? 'pending',
                'source' => $data['source'] ?? 'upload',
                'priority' => $data['priority'] ?? 'normal',
                'notes' => $data['notes'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ]);
            
            return $intake;
        }

        try {
            // Use multi-file creation service for proper handling
            if (count($uploadedFiles) > 1) {
                // Multiple files: use createFromMultipleFiles for proper aggregation
                $intake = $intakeCreationService->createFromMultipleFiles($uploadedFiles, [
                    'source'         => $data['source'] ?? 'multi_file_upload',
                    'service_type'   => $data['service_type'] ?? null,
                    'notes'          => $data['notes'] ?? null,
                    'priority'       => $data['priority'] ?? 'normal',
                    'customer_name'  => $data['customer_name'] ?? null,
                    'contact_email'  => $data['contact_email'] ?? null,
                    'contact_phone'  => $data['contact_phone'] ?? null,
                    'extraction_data'=> [
                        'contact' => array_filter([
                            'name'  => $data['customer_name'] ?? null,
                            'email' => $data['contact_email'] ?? null,
                            'phone' => $data['contact_phone'] ?? null,
                        ]),
                    ],
                ]);
                
                $processedCount = count($uploadedFiles);
                $failedCount = 0;
            } else {
                // Single file: use original logic
                $first = array_shift($uploadedFiles);
                $intake = $intakeCreationService->createFromUploadedFile($first, [
                    'source'         => $data['source'] ?? 'upload',
                    'service_type'   => $data['service_type'] ?? null,
                    'notes'          => $data['notes'] ?? null,
                    'priority'       => $data['priority'] ?? 'normal',
                    'customer_name'  => $data['customer_name'] ?? null,
                    'contact_email'  => $data['contact_email'] ?? null,
                    'contact_phone'  => $data['contact_phone'] ?? null,
                    'extraction_data'=> [
                        'contact' => array_filter([
                            'name'  => $data['customer_name'] ?? null,
                            'email' => $data['contact_email'] ?? null,
                            'phone' => $data['contact_phone'] ?? null,
                        ]),
                    ],
                ]);
                
                $processedCount = 1;
                $failedCount = 0;
            }

            // Show notification about upload results
            $message = "{$processedCount} file(s) uploaded and processing started";
            if ($failedCount > 0) {
                $message .= ". {$failedCount} file(s) failed to process.";
            }

            Notification::make()
                ->title('Intake created successfully')
                ->body($message)
                ->success()
                ->send();

            return $intake;
            
        } catch (\Exception $e) {
            \Log::error('CreateIntake: Failed to create intake', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            Notification::make()
                ->title('Failed to create intake')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->send();
                
            throw $e;
        }
    }
    
    protected function convertToUploadedFile($file): ?UploadedFile
    {
        if ($file instanceof UploadedFile) {
            return $file;
        }

        if ($file instanceof TemporaryUploadedFile) {
            try {
                // Livewire v3 helper to get a Symfony UploadedFile
                return $file->toUploadedFile();
            } catch (\Throwable $e) {
                \Log::error('Failed to convert TemporaryUploadedFile', [
                    'error' => $e->getMessage(),
                    'file_path' => $file->path(),
                    'filename' => $file->getClientOriginalName()
                ]);
                return null;
            }
        }

        // If your FileUpload was NOT set to storeFiles(false) and saved to a disk already,
        // sometimes you'll get a string path relative to that disk:
        if (is_string($file)) {
            // adjust disk name if you configured a different one
            $disk = config('filesystems.default', 'local');
            if (Storage::disk($disk)->exists($file)) {
                try {
                    $abs = Storage::disk($disk)->path($file);
                    return new UploadedFile($abs, basename($abs), null, null, true);
                } catch (\Throwable $e) {
                    \Log::error('Failed to create UploadedFile from storage path', [
                        'error' => $e->getMessage(),
                        'file_path' => $file,
                        'disk' => $disk
                    ]);
                    return null;
                }
            }

            // If it's a livewire-temp path on local disk:
            if (is_file($file)) {
                try {
                    return new UploadedFile($file, basename($file), null, null, true);
                } catch (\Throwable $e) {
                    \Log::error('Failed to create UploadedFile from file path', [
                        'error' => $e->getMessage(),
                        'file_path' => $file
                    ]);
                    return null;
                }
            }
        }

        \Log::warning('ConvertToUploadedFile: Could not convert file', [
            'file_type' => gettype($file),
            'file_class' => is_object($file) ? get_class($file) : null,
            'file_preview' => is_scalar($file) ? $file : 'non-scalar'
        ]);

        return null;
    }
    
    protected function getRedirectUrl(): string
    {
        \Log::info('CreateIntake: Redirecting to index page', [
            'intake_id' => $this->record->id ?? 'unknown'
        ]);
        
        return $this->getResource()::getUrl('index');
    }
}
