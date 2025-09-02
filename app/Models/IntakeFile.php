<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class IntakeFile extends Model
{
    protected $fillable = [
        'intake_id',
        'filename',
        'storage_path',
        'storage_disk',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /**
     * Get the file content from storage
     */
    public function getContent(): string
    {
        return Storage::disk($this->storage_disk)->get($this->storage_path);
    }

    /**
     * Check if the file exists in storage
     */
    public function exists(): bool
    {
        return Storage::disk($this->storage_disk)->exists($this->storage_path);
    }

    /**
     * Get the file URL (if publicly accessible)
     */
    public function getUrl(): ?string
    {
        if (Storage::disk($this->storage_disk)->exists($this->storage_path)) {
            return Storage::disk($this->storage_disk)->url($this->storage_path);
        }
        return null;
    }

    /**
     * Delete the file from storage
     */
    public function deleteFile(): bool
    {
        if ($this->exists()) {
            return Storage::disk($this->storage_disk)->delete($this->storage_path);
        }
        return true;
    }
}
