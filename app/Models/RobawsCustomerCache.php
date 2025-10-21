<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobawsCustomerCache extends Model
{
    protected $table = 'robaws_customers_cache';
    
    protected $fillable = [
        'robaws_client_id',
        'name',
        'role',
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
        'client_type',
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
     * Get all intakes for this customer
     */
    public function intakes(): HasMany
    {
        return $this->hasMany(Intake::class, 'robaws_client_id', 'robaws_client_id');
    }
    
    /**
     * Get role badge color for Filament
     */
    public function getRoleBadgeColor(): string
    {
        return match ($this->role) {
            'FORWARDER' => 'primary',
            'POV' => 'success',
            'BROKER' => 'warning',
            'SHIPPING LINE' => 'info',
            'CAR DEALER' => 'secondary',
            'LUXURY CAR DEALER' => 'success',
            'TOURIST' => 'danger',
            'BLACKLISTED' => 'danger',
            default => 'gray',
        };
    }
    
    /**
     * Scope for active customers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    /**
     * Scope for specific role
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }
    
    /**
     * Get full name with role
     */
    public function getFullNameWithRoleAttribute(): string
    {
        return $this->name . ($this->role ? " ({$this->role})" : '');
    }
}

