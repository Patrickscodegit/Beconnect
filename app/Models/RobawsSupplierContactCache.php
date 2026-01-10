<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobawsSupplierContactCache extends Model
{
    protected $table = 'robaws_supplier_contacts_cache';
    
    protected $fillable = [
        'robaws_contact_id',
        'robaws_supplier_id',
        'name',
        'surname',
        'full_name',
        'email',
        'phone',
        'mobile',
        'position',
        'title',
        'is_primary',
        'receives_quotes',
        'receives_invoices',
        'metadata',
        'last_synced_at',
    ];
    
    protected $casts = [
        'is_primary' => 'boolean',
        'receives_quotes' => 'boolean',
        'receives_invoices' => 'boolean',
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
    ];
    
    /**
     * Relationship to supplier
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(RobawsSupplierCache::class, 'robaws_supplier_id', 'robaws_supplier_id');
    }
    
    /**
     * Get full name (combine name + surname)
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([$this->name, $this->surname]);
        return implode(' ', $parts) ?: 'Unknown';
    }
    
    /**
     * Set full name when name or surname changes
     */
    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($contact) {
            $parts = array_filter([$contact->name, $contact->surname]);
            $contact->full_name = implode(' ', $parts) ?: null;
        });
    }
}
