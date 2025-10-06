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
}