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
    ];

    protected $casts = [
        'notes' => 'array',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(Extraction::class);
    }
}
