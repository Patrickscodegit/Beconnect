<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class VehicleSpec extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'make',
        'model',
        'variant',
        'year',
        'length_m',
        'width_m',
        'height_m',
        'wheelbase_m',
        'weight_kg',
        'engine_cc',
        'fuel_type',
        'wmi_id',
    ];

    protected $casts = [
        'year' => 'integer',
        'length_m' => 'decimal:2',
        'width_m' => 'decimal:2',
        'height_m' => 'decimal:2',
        'wheelbase_m' => 'decimal:2',
        'weight_kg' => 'integer',
        'engine_cc' => 'integer',
    ];

    public function vinWmi(): BelongsTo
    {
        return $this->belongsTo(VinWmi::class, 'wmi_id');
    }

    public function toSearchableArray(): array
    {
        return [
            'make' => $this->make,
            'model' => $this->model,
            'variant' => $this->variant,
            'year' => $this->year,
            'fuel_type' => $this->fuel_type,
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->make . ' ' . $this->model . ' ' . $this->variant);
    }

    public function isElectric(): bool
    {
        return in_array($this->fuel_type, ['electric', 'phev']);
    }
}
