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
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'has_text_layer' => 'boolean',
        'file_size' => 'integer',
        'page_count' => 'integer',
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
