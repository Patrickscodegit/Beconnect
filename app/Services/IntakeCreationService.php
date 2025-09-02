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

        // Decode and store the file
        $fileData = base64_decode($base64Data);
        $storagePath = 'intakes/' . $intake->id . '/' . $filename;
        
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
        ]);

        // Store text as a .txt file
        $filename = 'text_input_' . now()->format('Y-m-d_H-i-s') . '.txt';
        $storagePath = 'intakes/' . $intake->id . '/' . $filename;
        
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

    private function storeFile(Intake $intake, UploadedFile $file, string $originalName): void
    {
        $filename = Str::uuid() . '_' . $originalName;
        $storagePath = 'intakes/' . $intake->id . '/' . $filename;
        
        $path = $file->storeAs('intakes/' . $intake->id, $filename, 'local');

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $originalName,
            'storage_path' => $path,
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
