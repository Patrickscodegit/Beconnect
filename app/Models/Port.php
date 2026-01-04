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
        'unlocode',
        'country_code',
        'port_category',
        'iata_code',
        'icao_code',
        'city_unlocode',
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

    public function scopeSallaumDestinations($query)
    {
        return $query->whereHas('podSchedules', function($q) {
            $q->where('is_active', true)
              ->whereHas('carrier', function($carrier) {
                  $carrier->where('name', 'Sallaum Lines');
              });
        })->where('is_active', true);
    }

    public function scopeBelgianOrigins($query)
    {
        return $query->whereIn('name', ['Antwerp', 'Flushing', 'Zeebrugge'])
            ->where('is_active', true);
    }

    /**
     * Scope to find all ports for a specific city UN/LOCODE
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $unlocode
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCityUnlocode($query, string $unlocode)
    {
        return $query->where('city_unlocode', $unlocode);
    }

    /**
     * Scope to get active airports
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAirports($query)
    {
        return $query->where('port_category', 'AIRPORT')
            ->where('is_active', true);
    }

    /**
     * Scope to get active seaports
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSeaports($query)
    {
        return $query->where('port_category', 'SEA_PORT')
            ->where('is_active', true);
    }

    public function getFullNameAttribute(): string
    {
        return $this->name . ($this->country ? ', ' . $this->country : '');
    }

    /**
     * Format port in standard format: "City (CODE), Country"
     * Example: "Antwerp (ANR), Belgium"
     * 
     * @return string
     */
    public function formatFull(): string
    {
        return $this->name . ' (' . $this->code . '), ' . $this->country;
    }

    /**
     * Format port in short format: "City (CODE)"
     * Example: "Antwerp (ANR)"
     * 
     * @return string
     */
    public function formatShort(): string
    {
        return $this->name . ' (' . $this->code . ')';
    }

    /**
     * Find port by code (case-insensitive)
     * 
     * @param string $code
     * @return Port|null
     */
    public static function findByCodeInsensitive(string $code): ?Port
    {
        return static::whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
    }

    /**
     * Find port by name (case-insensitive)
     * 
     * @param string $name
     * @return Port|null
     */
    public static function findByNameInsensitive(string $name): ?Port
    {
        return static::whereRaw('UPPER(name) = ?', [strtoupper(trim($name))])->first();
    }

    /**
     * Relationship to PortAlias
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PortAlias::class);
    }

    /**
     * Check if this port is referenced by schedules, mappings, or articles.
     * Uses EXISTS queries for performance.
     */
    public function isReferenced(): bool
    {
        return \App\Models\ShippingSchedule::where('pol_id', $this->id)->exists()
            || \App\Models\ShippingSchedule::where('pod_id', $this->id)->exists()
            || \App\Models\CarrierArticleMapping::whereJsonContains('port_ids', $this->id)->exists()
            || \App\Models\RobawsArticleCache::where('pod_code', $this->code)->exists();
    }

    /**
     * Get summary of which sources reference this port.
     * Returns array of booleans per source for UI messaging.
     */
    public function referenceSummary(): array
    {
        return [
            'schedules' => \App\Models\ShippingSchedule::where('pol_id', $this->id)
                ->orWhere('pod_id', $this->id)->exists(),
            'mappings' => \App\Models\CarrierArticleMapping::whereJsonContains('port_ids', $this->id)->exists(),
            'robaws_cache' => \App\Models\RobawsArticleCache::where('pod_code', $this->code)->exists(),
        ];
    }

    /**
     * Get all active facilities for the same city (same city_unlocode)
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCityFacilities()
    {
        if (!$this->city_unlocode) {
            return collect([$this]);
        }

        return static::byCityUnlocode($this->city_unlocode)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get display name with facility type indicator
     * 
     * @return string
     */
    public function getDisplayName(): string
    {
        if ($this->port_category === 'AIRPORT' && $this->iata_code) {
            return "{$this->name} – Airport ({$this->iata_code})";
        }

        if ($this->port_category === 'SEA_PORT') {
            return "{$this->name} – Seaport";
        }

        // Fallback to existing format
        return $this->formatFull();
    }
}