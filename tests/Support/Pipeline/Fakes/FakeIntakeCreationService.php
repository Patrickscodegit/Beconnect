<?php

namespace Tests\Support\Pipeline\Fakes;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Services\IntakeCreationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FakeIntakeCreationService extends IntakeCreationService
{
    public function createFromUploadedFile(TemporaryUploadedFile|UploadedFile $file, array $options = []): Intake
    {
        $intake = Intake::create([
            'status' => 'processing',
            'source' => $options['source'] ?? 'upload',
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
            'contact_phone' => $options['contact_phone'] ?? null,
        ]);

        $disk = 'documents';
        $name = Str::uuid() . '.' . Str::lower($file->getClientOriginalExtension() ?? 'tmp');
        $path = $name;

        Storage::disk($disk)->put($path, $file->get());

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : $name,
            'storage_path' => $path,
            'storage_disk' => $disk,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return $intake;
    }

    public function createFromText(string $text, array $options = []): Intake
    {
        $intake = Intake::create([
            'status' => 'pending',
            'source' => $options['source'] ?? 'text_input',
            'priority' => $options['priority'] ?? 'normal',
            'customer_name' => $options['customer_name'] ?? null,
            'contact_email' => $options['contact_email'] ?? null,
        ]);

        $disk = 'documents';
        $filename = 'text_input_' . Str::uuid() . '.txt';
        $path = 'documents/' . Str::uuid() . '.txt';

        Storage::disk($disk)->put($path, $text);

        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $path,
            'storage_disk' => $disk,
            'mime_type' => 'text/plain',
            'file_size' => strlen($text),
        ]);

        return $intake;
    }
}

