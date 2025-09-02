<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Intake extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'source',
        'notes',
        'priority',
        'robaws_offer_id',
        'robaws_offer_number',
        'extraction_data',
        'export_payload_hash',
        'export_attempt_count',
        'last_export_error',
    ];

    protected $casts = [
        'notes' => 'array',
        'extraction_data' => 'array',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(Extraction::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(IntakeFile::class);
    }
}
