<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobawsSupplierCache extends Model
{
    protected $table = 'robaws_suppliers_cache';
    
    protected $fillable = [
        'robaws_supplier_id',
        'name',
        'code',
        'supplier_type',
        'email',
        'phone',
        'mobile',
        'address',
        'street',
        'street_number',
        'city',
        'postal_code',
        'country',
        'country_code',
        'vat_number',
        'website',
        'language',
        'currency',
        'supplier_category',
        'is_active',
        'metadata',
        'last_synced_at',
        'last_pushed_to_robaws_at',
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
        'last_pushed_to_robaws_at' => 'datetime',
    ];
    
    /**
     * Get all shipping carriers linked to this supplier
     */
    public function shippingCarriers(): HasMany
    {
        return $this->hasMany(ShippingCarrier::class, 'robaws_supplier_id');
    }
    
    /**
     * Get all contacts for this supplier
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(RobawsSupplierContactCache::class, 'robaws_supplier_id', 'robaws_supplier_id');
    }
    
    /**
     * Get primary contact for this supplier
     */
    public function getPrimaryContactAttribute()
    {
        return $this->contacts()->where('is_primary', true)->first();
    }
    
    /**
     * Get supplier type badge color for Filament
     */
    public function getSupplierTypeBadgeColor(): string
    {
        return match ($this->supplier_type) {
            'shipping_line' => 'primary',
            'vendor' => 'success',
            'carrier' => 'info',
            default => 'gray',
        };
    }
    
    /**
     * Scope for active suppliers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    /**
     * Scope for specific supplier type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('supplier_type', $type);
    }
    
    /**
     * Get full name with type
     */
    public function getFullNameWithTypeAttribute(): string
    {
        return $this->name . ($this->supplier_type ? " ({$this->supplier_type})" : '');
    }
    
    /**
     * Get name with details for display (includes ID and code for disambiguation)
     */
    public function getNameWithDetailsAttribute(): string
    {
        $details = [$this->name];
        
        if ($this->code) {
            $details[] = "Code: {$this->code}";
        }
        if ($this->email) {
            $details[] = $this->email;
        }
        if ($this->city) {
            $details[] = $this->city;
        }
        $details[] = "ID: {$this->robaws_supplier_id}";
        
        return implode(' • ', $details);
    }
}
