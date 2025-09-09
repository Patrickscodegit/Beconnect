<?php

namespace App\Services;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\ProcessIntake;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class IntakeCreationService
{
    public function createFromUploadedFile(TemporaryUploadedFile|UploadedFile $file, array $options = []): Intake
    {
        // Detect file type and determine initial status
        $mimeType = $file->getMimeType();
        $initialStatus = $this->determineInitialStatus($mimeType, $options);
        
        $intake = Intake::create([
            'status' => $initialStatus,
            'source' => $options['source'] ?? 'file_upload',
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        $this->storeFile($intake, $file, $file->getClientOriginalName());
        
        // Always process the intake - extraction will handle contact info
        ProcessIntake::dispatch($intake);
        
        Log::info('Created intake from uploaded file', [
            'intake_id' => $intake->id,
            'filename' => $file->getClientOriginalName(),
            'source' => $intake->source,
            'mime_type' => $mimeType,
            'initial_status' => $initialStatus
        ]);

        return $intake;
    }

    public function createFromBase64Image(string $base64Data, string $filename = null, array $options = []): Intake
    {
        // Remove data URL prefix if present
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $base64Data, $matches)) {
            $mimeType = $matches[1];
            $base64Data = $matches[2];
        } else {
            $mimeType = 'image/png'; // Default assumption
        }

        // Images should always go to extraction
        $initialStatus = $this->determineInitialStatus($mimeType, $options);
        
        $intake = Intake::create([
            'status' => $initialStatus,
            'source' => $options['source'] ?? 'screenshot',
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        // Generate filename if not provided
        if (!$filename) {
            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = 'screenshot_' . now()->format('Y-m-d_H-i-s') . '.' . $extension;
        }

        // Decode and store the file
        $fileData = base64_decode($base64Data);
        $ext = Str::lower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'png');
        $name = Str::uuid() . '.' . $ext;
        $dir = 'documents';
        $storagePath = $dir . '/' . $name;
        $disk = 'documents'; // Use environment-aware documents disk
        
        Storage::disk($disk)->put($storagePath, $fileData);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => $disk,
            'mime_type' => $mimeType,
            'file_size' => strlen($fileData),
        ]);

        ProcessIntake::dispatch($intake);
        
        Log::info('Created intake from base64 image', [
            'intake_id' => $intake->id,
            'filename' => $filename,
            'source' => $intake->source,
            'mime_type' => $mimeType,
            'initial_status' => $initialStatus
        ]);

        return $intake;
    }

    public function createFromText(string $text, array $options = []): Intake
    {
        $intake = Intake::create([
            'status' => 'pending',
            'source' => $options['source'] ?? 'text_input',
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        // Store text as a .txt file
        $filename = 'text_input_' . now()->format('Y-m-d_H-i-s') . '.txt';
        $name = Str::uuid() . '.txt';
        $dir = 'documents';
        $storagePath = $dir . '/' . $name;
        $disk = 'documents'; // Use environment-aware documents disk
        
        Storage::disk($disk)->put($storagePath, $text);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => $disk,
            'mime_type' => 'text/plain',
            'file_size' => strlen($text),
        ]);

        ProcessIntake::dispatch($intake);
        
        Log::info('Created intake from text input', [
            'intake_id' => $intake->id,
            'filename' => $filename,
            'source' => $intake->source
        ]);

        return $intake;
    }

    public function createFromEmail(string $emailContent, array $options = []): Intake
    {
        $intake = Intake::create([
            'status' => 'pending',
            'source' => $options['source'] ?? 'email',
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        // Store email as a .eml file
        $filename = 'email_' . now()->format('Y-m-d_H-i-s') . '.eml';
        $name = Str::uuid() . '.eml';
        $dir = 'documents';
        $storagePath = $dir . '/' . $name;
        $disk = 'documents'; // Use environment-aware documents disk
        
        Storage::disk($disk)->put($storagePath, $emailContent);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => $disk,
            'mime_type' => 'message/rfc822',
            'file_size' => strlen($emailContent),
        ]);

        ProcessIntake::dispatch($intake);
        
        Log::info('Created intake from email', [
            'intake_id' => $intake->id,
            'filename' => $filename,
            'source' => $intake->source
        ]);

        return $intake;
    }

    public function addFileToIntake(Intake $intake, TemporaryUploadedFile|UploadedFile $file): IntakeFile
    {
        $storagePath = $this->storeFileOnly($file);

        $intakeFile = IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'storage_disk' => 'documents',
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

        Log::info('Added file to existing intake', [
            'intake_id' => $intake->id,
            'file_id' => $intakeFile->id,
            'filename' => $intakeFile->filename
        ]);

        return $intakeFile;
    }

    private function storeFile(Intake $intake, TemporaryUploadedFile|UploadedFile $file, string $originalName): void
    {
        $storagePath = $this->storeFileOnly($file);
        
        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $originalName,
            'storage_path' => $storagePath,
            'storage_disk' => 'documents',
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }

    /**
     * S3-safe file storage - never treats Spaces keys as local paths
     */
    private function storeFileOnly(TemporaryUploadedFile|UploadedFile $file): string
    {
        $disk = 'documents';           // env-aware disk
        $dir  = '';                    // (root of the documents disk)
        $ext  = strtolower($file->getClientOriginalExtension() ?? '');
        $name = (string) Str::uuid() . ($ext ? ".$ext" : '');

        // Works for both TemporaryUploadedFile (Livewire) and classic UploadedFile
        return $file->storeAs($dir, $name, $disk);
    }

    /**
     * Determine initial status based on file type and metadata
     * Images and PDFs should go directly to extraction, not block on contact
     */
    private function determineInitialStatus(string $mimeType, array $options): string
    {
        // If contact info is explicitly provided, proceed directly
        if (!empty($options['contact_email']) || !empty($options['customer_name'])) {
            return 'pending';
        }
        
        // Check configuration for file types that skip contact validation
        $skipContactTypes = config('intake.processing.skip_contact_validation', [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'message/rfc822'
        ]);
        
        // For configured file types, always proceed to extraction
        if (in_array($mimeType, $skipContactTypes)) {
            return 'pending';
        }
        
        // Check if contact validation is disabled globally
        if (!config('intake.processing.require_contact_info', false)) {
            return 'pending';
        }
        
        // Default to pending - let the extraction process handle contact requirements
        return 'pending';
    }

    /**
     * Check if mime type should skip contact validation based on configuration
     */
    private function shouldSkipContactValidation(string $mimeType): bool
    {
        $skipContactTypes = config('intake.processing.skip_contact_validation', [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'message/rfc822'
        ]);
        
        return in_array($mimeType, $skipContactTypes);
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
        ];

        return $extensions[$mimeType] ?? 'png';
    }
}
