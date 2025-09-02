<?php

namespace App\Services;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\ProcessIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class IntakeCreationService
{
    public function createFromUploadedFile(UploadedFile $file, array $options = []): Intake
    {
        $intake = Intake::create([
            'status' => 'pending',
            'source' => $options['source'] ?? 'file_upload',
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        $this->storeFile($intake, $file, $file->getClientOriginalName());
        
        ProcessIntake::dispatch($intake);
        
        Log::info('Created intake from uploaded file', [
            'intake_id' => $intake->id,
            'filename' => $file->getClientOriginalName(),
            'source' => $intake->source
        ]);

        return $intake;
    }

    public function createFromBase64Image(string $base64Data, string $filename = null, array $options = []): Intake
    {
        $intake = Intake::create([
            'status' => 'pending',
            'source' => $options['source'] ?? 'screenshot',
            'notes' => $options['notes'] ?? null,
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
            'extraction_data' => $options['extraction_data'] ?? null,
        ]);

        // Remove data URL prefix if present
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $base64Data, $matches)) {
            $mimeType = $matches[1];
            $base64Data = $matches[2];
        } else {
            $mimeType = 'image/png'; // Default assumption
        }

        // Generate filename if not provided
        if (!$filename) {
            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = 'screenshot_' . now()->format('Y-m-d_H-i-s') . '.' . $extension;
        }

        // Decode and store the file - CONSISTENT PATH
        $fileData = base64_decode($base64Data);
        $ext = Str::lower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'png');
        $name = Str::uuid() . '.' . $ext;
        $dir = 'intakes/' . date('Y/m/d');  // ← Consistent with uploaded files
        $storagePath = $dir . '/' . $name;
        
        Storage::disk('local')->put($storagePath, $fileData);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => 'local',
            'mime_type' => $mimeType,
            'file_size' => strlen($fileData),
        ]);

        ProcessIntake::dispatch($intake);
        
        Log::info('Created intake from base64 image', [
            'intake_id' => $intake->id,
            'filename' => $filename,
            'source' => $intake->source
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

        // Store text as a .txt file - CONSISTENT PATH
        $filename = 'text_input_' . now()->format('Y-m-d_H-i-s') . '.txt';
        $name = Str::uuid() . '.txt';
        $dir = 'intakes/' . date('Y/m/d');  // ← Consistent with other files
        $storagePath = $dir . '/' . $name;
        
        Storage::disk('local')->put($storagePath, $text);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => 'local',
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

        // Store email as a .eml file - CONSISTENT PATH
        $filename = 'email_' . now()->format('Y-m-d_H-i-s') . '.eml';
        $name = Str::uuid() . '.eml';
        $dir = 'intakes/' . date('Y/m/d');
        $storagePath = $dir . '/' . $name;
        
        Storage::disk('local')->put($storagePath, $emailContent);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => 'local',
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

    public function addFileToIntake(Intake $intake, UploadedFile $file): IntakeFile
    {
        // CONSISTENT PATH STRUCTURE - intakes/Y/m/d/uuid.ext
        $extension = $file->getClientOriginalExtension();
        $name = Str::uuid() . '.' . $extension;
        $dir = 'intakes/' . date('Y/m/d');
        $storagePath = $dir . '/' . $name;
        
        // Store with the consistent structure
        Storage::disk('local')->putFileAs($dir, $file, $name);

        $intakeFile = IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'storage_disk' => 'local',
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

    private function storeFile(Intake $intake, UploadedFile $file, string $originalName): void
    {
        // CONSISTENT PATH STRUCTURE - intakes/Y/m/d/uuid.ext
        $extension = $file->getClientOriginalExtension();
        $name = Str::uuid() . '.' . $extension;
        $dir = 'intakes/' . date('Y/m/d');
        $storagePath = $dir . '/' . $name;
        
        // Store with the consistent structure
        Storage::disk('local')->putFileAs($dir, $file, $name);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $originalName,
            'storage_path' => $storagePath,
            'storage_disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
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
