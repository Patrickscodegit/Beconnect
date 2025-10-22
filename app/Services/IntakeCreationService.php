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
use Illuminate\Support\Facades\DB;

class IntakeCreationService
{
    /**
     * Validate that an intake has at least one file attached
     */
    private function validateIntakeHasFiles(Intake $intake): void
    {
        $fileCount = $intake->files()->count();
        
        if ($fileCount === 0) {
            Log::warning('Intake created without any files', [
                'intake_id' => $intake->id,
                'source' => $intake->source,
                'status' => $intake->status
            ]);
            
            // Update intake status to indicate the issue
            $intake->update([
                'status' => 'failed',
                'notes' => array_merge($intake->notes ?? [], [
                    'validation_error' => 'No files attached to intake',
                    'error_time' => now()->toISOString()
                ])
            ]);
            
            throw new \InvalidArgumentException("Intake must have at least one file attached");
        }
    }

    /**
     * Validate intake data before processing
     */
    private function validateIntakeData(array $options): void
    {
        // Check for minimum required data
        if (empty($options['customer_name']) && empty($options['contact_email'])) {
            Log::warning('Intake created without customer name or email', [
                'options' => array_keys($options),
                'has_customer_name' => !empty($options['customer_name']),
                'has_contact_email' => !empty($options['contact_email'])
            ]);
        }
    }

    public function createFromUploadedFile(TemporaryUploadedFile|UploadedFile $file, array $options = []): Intake
    {
        // Validate intake data
        $this->validateIntakeData($options);
        
        // Detect file type and determine initial status
        $mimeType = $file->getMimeType();
        $initialStatus = $this->determineInitialStatus($mimeType, $options);
        
        $intake = Intake::create([
            'status' => 'processing', // More user-friendly status
            'source' => $options['source'] ?? 'file_upload',
            'service_type' => $options['service_type'] ?? null,
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        // Store file immediately (minimal operation - just move temp file)
        $this->storeFileMinimal($intake, $file);
        
        // Validate that intake has files
        $this->validateIntakeHasFiles($intake);
        
        // Ensure database transaction is committed before dispatching job
        // Use a separate queue connection to avoid transaction context issues
        DB::afterCommit(function () use ($intake) {
            // Dispatch to a separate queue connection to avoid transaction context issues
            ProcessIntake::dispatchSync($intake);
        });
        
        Log::info('Created intake from uploaded file', [
            'intake_id' => $intake->id,
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

        // Ensure database transaction is committed before dispatching job
        // Use a separate queue connection to avoid transaction context issues
        DB::afterCommit(function () use ($intake) {
            // Dispatch to a separate queue connection to avoid transaction context issues
            ProcessIntake::dispatchSync($intake);
        });
        
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

    /**
     * Create intake from multiple files uploaded together
     * All files will be processed and contribute to ONE Robaws offer
     *
     * @param array $files Array of UploadedFile instances
     * @param array $options Options including customer_name, contact_email, etc.
     * @return Intake
     */
    public function createFromMultipleFiles(array $files, array $options = []): Intake
    {
        Log::info('Creating multi-file intake', [
            'files_count' => count($files),
            'source' => $options['source'] ?? 'multi_file_upload',
            'customer_name' => $options['customer_name'] ?? null
        ]);

        // Create intake with multi-document flag
        $intake = Intake::create([
            'status' => 'processing',
            'source' => $options['source'] ?? 'multi_file_upload',
            'service_type' => $options['service_type'] ?? null,
            'is_multi_document' => true,
            'total_documents' => count($files),
            'processed_documents' => 0,
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        // Store all files
        foreach ($files as $index => $file) {
            // Create both IntakeFile and Document models for multi-file intakes
            $intakeFile = $this->addFileToIntake($intake, $file);
            
            // Also create Document model for extraction processing
            $this->createDocumentFromIntakeFile($intake, $intakeFile);
            
            Log::info('Added file to multi-file intake', [
                'intake_id' => $intake->id,
                'file_index' => $index + 1,
                'total_files' => count($files),
                'filename' => $file->getClientOriginalName()
            ]);
        }

        // Dispatch processing job
        DB::afterCommit(function () use ($intake) {
            ProcessIntake::dispatchSync($intake);
        });

        Log::info('Multi-file intake created successfully', [
            'intake_id' => $intake->id,
            'total_files' => count($files),
            'is_multi_document' => $intake->is_multi_document
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

    /**
     * Create Document model from IntakeFile for extraction processing
     */
    private function createDocumentFromIntakeFile(Intake $intake, IntakeFile $intakeFile): void
    {
        \App\Models\Document::create([
            'intake_id' => $intake->id,
            'filename' => $intakeFile->filename,
            'original_filename' => $intakeFile->filename,
            'file_path' => $intakeFile->storage_path,
            'filepath' => $intakeFile->storage_path, // Alternative field name
            'mime_type' => $intakeFile->mime_type,
            'file_size' => $intakeFile->file_size,
            'storage_disk' => $intakeFile->storage_disk,
            'status' => 'pending', // Will be processed by extraction
            'extraction_status' => 'pending',
            'extraction_confidence' => 0.0,
        ]);
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
    
    /**
     * Store file with minimal operations (ultra-fast)
     */
    private function storeFileMinimal(Intake $intake, $file): void
    {
        try {
            // Get minimal info (fastest possible operations)
            $originalName = $file->getClientOriginalName();
            
            // Use Livewire's proper method to get file content (works in all environments)
            $fileContent = $file->get();
            
            // Just move the temp file to a permanent location (minimal operation)
            $disk = 'documents';
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?? '');
            $name = (string) \Illuminate\Support\Str::uuid() . ($ext ? ".$ext" : '');
            $storagePath = $name;
            
            // Move file (fastest possible operation)
            \Illuminate\Support\Facades\Storage::disk($disk)->put($storagePath, $fileContent);
            
            // Create minimal IntakeFile record
            \App\Models\IntakeFile::create([
                'intake_id' => $intake->id,
                'filename' => $originalName,
                'storage_path' => $storagePath,
                'storage_disk' => $disk,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to store file minimally', [
                'intake_id' => $intake->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
}
