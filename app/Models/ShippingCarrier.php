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

    // Carrier Rules relationships
    public function categoryGroups(): HasMany
    {
        return $this->hasMany(CarrierCategoryGroup::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function acceptanceRules(): HasMany
    {
        return $this->hasMany(CarrierAcceptanceRule::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function transformRules(): HasMany
    {
        return $this->hasMany(CarrierTransformRule::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function surchargeRules(): HasMany
    {
        return $this->hasMany(CarrierSurchargeRule::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function surchargeArticleMaps(): HasMany
    {
        return $this->hasMany(CarrierSurchargeArticleMap::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function clauses(): HasMany
    {
        return $this->hasMany(CarrierClause::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function portGroups(): HasMany
    {
        return $this->hasMany(CarrierPortGroup::class, 'carrier_id')
            ->orderBy('sort_order');
    }

    public function articleMappings(): HasMany
    {
        return $this->hasMany(CarrierArticleMapping::class, 'carrier_id')
            ->orderBy('sort_order');
    }
}