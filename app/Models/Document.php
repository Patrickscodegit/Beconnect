<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'intake_id',
        'filename',
        'file_path',
        'mime_type',
        'file_size',
        'has_text_layer',
        'document_type',
        'page_count',
    ];

    protected $casts = [
        'has_text_layer' => 'boolean',
        'file_size' => 'integer',
        'page_count' => 'integer',
    ];

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
