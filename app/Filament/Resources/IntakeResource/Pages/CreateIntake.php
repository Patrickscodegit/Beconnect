<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use App\Models\Intake;
use App\Services\IntakeCreationService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class CreateIntake extends CreateRecord
{
    protected static string $resource = IntakeResource::class;
    
    protected function handleRecordCreation(array $data): Intake
    {
        \Log::info('CreateIntake: Starting record creation with new service', [
            'data_keys' => array_keys($data),
            'has_intake_files' => isset($data['intake_files']),
            'intake_files_count' => isset($data['intake_files']) ? count($data['intake_files']) : 0
        ]);
        
        $intakeCreationService = app(IntakeCreationService::class);
        $uploadedFiles = $data['intake_files'] ?? [];
        
        // Remove files from intake data for cleaner creation
        unset($data['intake_files']);
        
        if (empty($uploadedFiles)) {
            // Create simple intake without files
            $intake = Intake::create($data);
            
            Notification::make()
                ->title('Intake created')
                ->body('Intake created successfully without files.')
                ->success()
                ->send();
                
            return $intake;
        }
        
        // Process the first file to create the intake
        $firstFile = array_shift($uploadedFiles);
        $uploadedFile = $this->convertToUploadedFile($firstFile);
        
        if (!$uploadedFile) {
            throw new \Exception('Could not process uploaded file');
        }
        
        try {
            // Create intake from first file using the service
            $intake = $intakeCreationService->createFromUploadedFile($uploadedFile, [
                'source' => $data['source'] ?? 'upload',
                'notes' => $data['notes'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'customer_name' => $data['customer_name'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ]);
            
            $processedCount = 1;
            $failedCount = 0;
            
            // Add any additional files to the intake
            foreach ($uploadedFiles as $fileData) {
                try {
                    $additionalFile = $this->convertToUploadedFile($fileData);
                    if ($additionalFile) {
                        $intakeCreationService->addFileToIntake($intake, $additionalFile);
                        $processedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to add additional file to intake', [
                        'intake_id' => $intake->id,
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }
            }
            
            // Show notification about upload results
            $message = "{$processedCount} file(s) uploaded and processed";
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
    
    private function convertToUploadedFile(string $tempPath): ?UploadedFile
    {
        try {
            // Clean up the path
            $cleanFileName = str_replace('temp-uploads/', '', $tempPath);
            
            // Try multiple possible paths for Filament/Livewire temporary files
            $possiblePaths = [
                Storage::disk('local')->path('temp-uploads/' . $cleanFileName),
                Storage::disk('local')->path($cleanFileName),
                storage_path('app/livewire-tmp/' . $cleanFileName),
                storage_path('app/private/temp-uploads/' . $cleanFileName),
                storage_path('app/private/livewire-tmp/' . $cleanFileName),
                $tempPath // Direct path
            ];
            
            $realPath = null;
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $realPath = $path;
                    break;
                }
            }
            
            if (!$realPath) {
                \Log::error('ConvertToUploadedFile: File not found', [
                    'temp_path' => $tempPath,
                    'checked_paths' => $possiblePaths
                ]);
                return null;
            }
            
            // Create UploadedFile instance
            $originalName = basename($cleanFileName);
            $mimeType = mime_content_type($realPath);
            
            return new UploadedFile(
                $realPath,
                $originalName,
                $mimeType,
                null,
                true // test mode - allows using any file path
            );
            
        } catch (\Exception $e) {
            \Log::error('ConvertToUploadedFile: Exception', [
                'temp_path' => $tempPath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
