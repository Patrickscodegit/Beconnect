<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Port extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'country',
        'region',
        'coordinates',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'shipping_codes' => 'array',
        'is_european_origin' => 'boolean',
        'is_african_destination' => 'boolean',
    ];

    public function polSchedules(): HasMany
    {
        return $this->hasMany(ShippingSchedule::class, 'pol_id');
    }

    public function podSchedules(): HasMany
    {
        return $this->hasMany(ShippingSchedule::class, 'pod_id');
    }

    public function getActivePolSchedules()
    {
        return $this->polSchedules()->where('is_active', true);
    }

    public function getActivePodSchedules()
    {
        return $this->podSchedules()->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeEuropeanOrigins($query)
    {
        // Explicitly list the 3 European POL ports for quotation forms
        // This ensures reliability regardless of database flag values
        return $query->whereIn('name', ['Antwerp', 'Flushing', 'Zeebrugge'])
            ->where('is_active', true)
            ->orderBy('name');
    }

    public function scopeAfricanDestinations($query)
    {
        return $query->where('is_african_destination', true)->where('is_active', true);
    }

    public function scopeForPol($query)
    {
        return $query->whereIn('port_type', ['pol', 'both'])->where('is_active', true);
    }

    public function scopeForPod($query)
    {
        return $query->whereIn('port_type', ['pod', 'both'])->where('is_active', true);
    }

    public function scopeWithActivePodSchedules($query)
    {
        return $query->whereHas('podSchedules', function($q) {
            $q->where('is_active', true);
        })->where('is_active', true);
    }

    public function scopeWithActivePolSchedules($query)
    {
        return $query->whereHas('polSchedules', function($q) {
            $q->where('is_active', true);
        })->where('is_active', true);
    }

    public function getFullNameAttribute(): string
    {
        return $this->name . ($this->country ? ', ' . $this->country : '');
    }
}