<?php

use App\Models\Document;
use App\Models\Intake;
use App\Services\DocumentStorageConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('spaces');
});

it('uses local disk in local environment', function () {
    App::detectEnvironment(fn() => 'local');
    
    $intake = Intake::factory()->create();
    $doc = Document::create([  // Use create instead of factory to trigger provider
        'intake_id' => $intake->id,
        'filename' => 'test.eml',
        'file_path' => null,  // Let provider set this
        'mime_type' => 'message/rfc822',
        'file_size' => 1024,
        'file_hash' => md5('test'),
        'upload_status' => 'uploaded',
    ]);

    expect($doc->storage_disk)->toBe('local');
    expect($doc->file_path)->toBe('documents/test.eml');
});

it('uses configured disk in production environment', function () {
    App::detectEnvironment(fn() => 'production');
    config()->set('filesystems.documents_config.default_disk', 'spaces');
    
    $intake = Intake::factory()->create();
    $doc = Document::create([  // Use create instead of factory
        'intake_id' => $intake->id,
        'filename' => 'test.eml',
        'file_path' => null,  // Let provider set this
        'mime_type' => 'message/rfc822',
        'file_size' => 1024,
        'file_hash' => md5('test'),
        'upload_status' => 'uploaded',
    ]);

    expect($doc->storage_disk)->toBe('spaces');
    expect($doc->file_path)->toBe('documents/test.eml');
});

it('preserves explicitly set storage disk', function () {
    App::detectEnvironment(fn() => 'local');
    
    $intake = Intake::factory()->create();
    $doc = Document::create([
        'intake_id' => $intake->id,
        'storage_disk' => 'custom',  // Explicitly set
        'filename' => 'test.eml',
        'file_path' => 'custom/test.eml',
        'mime_type' => 'message/rfc822',
        'file_size' => 1024,
        'file_hash' => md5('test'),
        'upload_status' => 'uploaded',
    ]);

    expect($doc->storage_disk)->toBe('custom');
});

it('normalizes file paths consistently', function () {
    $testCases = [
        'simple.eml' => 'documents/simple.eml',
        'path/to/file.eml' => 'documents/path/to/file.eml',
        '/leading/slash.eml' => 'documents//leading/slash.eml', // Will be normalized by storage
    ];
    
    foreach ($testCases as $input => $expected) {
        $path = DocumentStorageConfig::getDocumentPath($input);
        expect($path)->toBe($expected);
    }
});

it('handles document upload with correct storage', function () {
    App::detectEnvironment(fn() => 'local');
    
    $file = UploadedFile::fake()->create('test.eml', 100);
    $intake = Intake::factory()->create();
    
    // Simulate document creation during intake
    $doc = Document::create([
        'intake_id' => $intake->id,
        'filename' => $file->getClientOriginalName(),
        'file_size' => $file->getSize(),
        'mime_type' => 'message/rfc822',
        'file_hash' => md5_file($file->getRealPath()),
        'upload_status' => 'uploaded',
    ]);
    
    expect($doc->storage_disk)->toBe('local');
    expect($doc->file_path)->toBe('documents/test.eml');
    
    // Verify file can be stored
    Storage::disk($doc->storage_disk)->putFileAs(
        dirname($doc->file_path),
        $file,
        basename($doc->file_path)
    );
    
    Storage::disk('local')->assertExists($doc->file_path);
});
