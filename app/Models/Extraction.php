<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Extraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'intake_id',
        'status',
        'extracted_data',
        'raw_json',
        'confidence',
        'service_used',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'extracted_data' => 'array',
        'raw_json' => 'array',
        'confidence' => 'float',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the document that owns the extraction.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the intake that owns the extraction.
     */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /**
     * Get the user who verified this extraction.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
