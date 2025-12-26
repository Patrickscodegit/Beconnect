<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingCarrier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'website_url',
        'api_endpoint',
        'specialization',
        'service_types',
        'service_level',
        'is_active',
        'internal_comments',
    ];

    protected $casts = [
        'specialization' => 'array',
        'service_types' => 'array',
        'is_active' => 'boolean',
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(ShippingSchedule::class);
    }

    public function getActiveSchedules()
    {
        return $this->schedules()->where('is_active', true);
    }

    public function hasServiceType(string $serviceType): bool
    {
        return in_array($serviceType, $this->service_types ?? []);
    }


}