<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'currency',
        'carrier_id',
        'robaws_client_id',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(RobawsCustomerCache::class, 'robaws_client_id', 'robaws_client_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PricingRule::class)->orderBy('priority', 'asc');
    }

    public function scopeActive($query, $date = null)
    {
        $date = $date ?? now();
        
        return $query->where('is_active', true)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')
                  ->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $date);
            });
    }
}