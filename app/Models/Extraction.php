<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Extraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'intake_id',
        'raw_json',
        'confidence',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'raw_json' => 'array',
        'confidence' => 'decimal:2',
        'verified_at' => 'datetime',
    ];

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }
}
