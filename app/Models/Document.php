<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    /**
     * Canonical, de-duped fillable list
     */
    protected $fillable = [
        'intake_id',
        // canonical file meta
        'name',
        'filename',
        'original_filename',
        'path',           // preferred new field
        'file_path',      // legacy alias kept for BC
        'mime_type',
        'size',           // preferred new field
        'file_size',      // legacy alias kept for BC
        'storage_disk',
        'page_count',
        'has_text_layer',
        'document_type',
        'status',         // document approval status
        'metadata',

        // email deduplication
        'source_message_id',
        'source_content_sha',

        // extraction
        'extraction_data',
        'extraction_confidence',
        'extraction_service',
        'extraction_status',
        'extracted_at',

        // Robaws (quotation + sync)
        'robaws_quotation_id',
        'robaws_quotation_data',
        'robaws_formatted_at',
        'robaws_sync_status',
        'robaws_synced_at',

        // Robaws file uploads
        'robaws_document_id',
        'robaws_uploaded_at',
        'robaws_upload_attempted_at',
        'robaws_last_upload_sha',
        'robaws_last_sync_at',
        'upload_status',
        'upload_error',
        'upload_method',

        // generic processing
        'processing_status',
        'last_sync_at',
        'sync_error',
    ];

    protected $casts = [
        'metadata'                 => 'array',
        'size'                     => 'integer',
        'file_size'                => 'integer',
        'page_count'               => 'integer',
        'has_text_layer'           => 'boolean',

        'extraction_data'          => 'array',
        'extraction_confidence'    => 'float',
        'extracted_at'             => 'datetime',

        'robaws_quotation_data'    => 'array',
        'robaws_formatted_at'      => 'datetime',
        'robaws_synced_at'         => 'datetime',
        'robaws_uploaded_at'       => 'datetime',
        'robaws_upload_attempted_at'=> 'datetime',
        'robaws_last_sync_at'      => 'datetime',
        'last_sync_at'             => 'datetime',
    ];

    /**
     * Backward-compat accessor for `filepath` (maps to `file_path`)
     * No recursion; reads/writes raw attribute.
     */
    public function getFilepathAttribute(): ?string
    {
        if (array_key_exists('file_path', $this->attributes)) {
            return $this->attributes['file_path'];
        }
        return $this->getRawOriginal('file_path');
    }

    public function setFilepathAttribute($value): void
    {
        $this->attributes['file_path'] = $value;
    }

    /**
     * Storage disk accessor:
     * - Prefer the persisted DB value if present
     * - Otherwise fallback to configured default (tests can override)
     * - Do NOT override with env heuristics here (keeps tests deterministic)
     */
    public function getStorageDiskAttribute($value): string
    {
        if (!empty($value)) {
            return $value;
        }
        return (string) config('files.documents_disk', 'documents');
    }

    /**
     * Relationships
     */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    public function extractions(): HasMany
    {
        return $this->hasMany(Extraction::class);
    }
}
