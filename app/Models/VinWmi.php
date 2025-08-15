<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class VinWmi extends Model
{
    use HasFactory, Searchable;

    protected $table = 'vin_wmis';

    protected $fillable = [
        'wmi',
        'manufacturer',
        'country',
        'country_code',
        'start_year',
        'end_year',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'verified_at' => 'date',
        'start_year' => 'integer',
        'end_year' => 'integer',
    ];

    public function vehicleSpecs(): HasMany
    {
        return $this->hasMany(VehicleSpec::class, 'wmi_id');
    }

    public function toSearchableArray(): array
    {
        return [
            'wmi' => $this->wmi,
            'manufacturer' => $this->manufacturer,
            'country' => $this->country,
            'country_code' => $this->country_code,
        ];
    }

    public function isActive(int $year = null): bool
    {
        $year = $year ?? now()->year;
        
        return $this->start_year <= $year && 
               ($this->end_year === null || $this->end_year >= $year);
    }
}