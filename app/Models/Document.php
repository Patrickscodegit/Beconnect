<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'intake_id',
        'name',
        'filename',
        'path',
        'disk',
        'mime_type',
        'size',
        'metadata',
        // Legacy fields
        'file_path',
        'file_size',
        'has_text_layer',
        'document_type',
        'page_count',
        // AI Extraction fields
        'extraction_data',
        'extraction_confidence',
        'extraction_service',
        'extraction_status',
        'extracted_at',
        // Robaws integration fields
        'robaws_quotation_id',
        'robaws_quotation_data',
        'robaws_formatted_at',
        'robaws_sync_status',
        'robaws_synced_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'has_text_layer' => 'boolean',
        'file_size' => 'integer',
        'page_count' => 'integer',
        'extraction_data' => 'array',
        'extraction_confidence' => 'float',
        'robaws_quotation_data' => 'array',
        'extracted_at' => 'datetime',
        'robaws_formatted_at' => 'datetime',
        'robaws_synced_at' => 'datetime',
    ];

    /**
     * Get the intake that owns the document.
     */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /**
     * Get the extractions for the document.
     */
    public function extractions(): HasMany
    {
        return $this->hasMany(Extraction::class);
    }
}
