<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StorageService
{
    /**
     * The storage disk to use
     */
    private string $disk = 's3';

    /**
     * Store a file and return its path
     */
    public function store(UploadedFile $file, string $directory = 'documents'): string
    {
        $filename = $this->generateUniqueFilename($file);
        $path = "{$directory}/{$filename}";
        
        Storage::disk($this->disk)->putFileAs($directory, $file, $filename);
        
        return $path;
    }

    /**
     * Store file content from a string
     */
    public function storeContent(string $content, string $path): bool
    {
        return Storage::disk($this->disk)->put($path, $content);
    }

    /**
     * Get a temporary URL for a private file
     */
    public function getTemporaryUrl(string $path, int $minutes = 60): string
    {
        return Storage::disk($this->disk)->temporaryUrl($path, now()->addMinutes($minutes));
    }

    /**
     * Check if a file exists
     */
    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    /**
     * Delete a file
     */
    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    /**
     * Get file contents
     */
    public function get(string $path): ?string
    {
        return Storage::disk($this->disk)->get($path);
    }

    /**
     * List files in a directory
     */
    public function files(string $directory = ''): array
    {
        return Storage::disk($this->disk)->files($directory);
    }

    /**
     * Get file size in bytes
     */
    public function size(string $path): int
    {
        return Storage::disk($this->disk)->size($path);
    }

    /**
     * Get file last modified time
     */
    public function lastModified(string $path): int
    {
        return Storage::disk($this->disk)->lastModified($path);
    }

    /**
     * Copy a file to a new location
     */
    public function copy(string $from, string $to): bool
    {
        return Storage::disk($this->disk)->copy($from, $to);
    }

    /**
     * Move a file to a new location
     */
    public function move(string $from, string $to): bool
    {
        return Storage::disk($this->disk)->move($from, $to);
    }

    /**
     * Get the URL for a public file
     */
    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Generate a unique filename
     */
    private function generateUniqueFilename(UploadedFile $file): string
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        
        return Str::slug($originalName) . '_' . time() . '.' . $extension;
    }

    /**
     * Set the storage disk
     */
    public function disk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    /**
     * Store a CSV file for vehicle data import
     */
    public function storeCsv(UploadedFile $file): string
    {
        return $this->store($file, 'csv-imports');
    }

    /**
     * Store vehicle images
     */
    public function storeVehicleImage(UploadedFile $file, string $vin): string
    {
        $directory = "vehicle-images/" . substr($vin, 0, 3);
        return $this->store($file, $directory);
    }

    /**
     * Store vehicle documents (manuals, specs, etc.)
     */
    public function storeVehicleDocument(UploadedFile $file, string $vin): string
    {
        $directory = "vehicle-documents/" . substr($vin, 0, 3);
        return $this->store($file, $directory);
    }
}
