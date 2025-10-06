<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'pol_id',
        'pod_id',
        'service_name',
        'frequency_per_week',
        'frequency_per_month',
        'transit_days',
        'vessel_name',
        'vessel_class',
        'ets_pol',
        'eta_pod',
        'next_sailing_date',
        'last_updated',
        'is_active',
    ];

    protected $casts = [
        'frequency_per_week' => 'decimal:1',
        'frequency_per_month' => 'decimal:1',
        'ets_pol' => 'date',
        'eta_pod' => 'date',
        'next_sailing_date' => 'date',
        'last_updated' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function polPort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'pol_id');
    }

    public function podPort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'pod_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPolPod($query, string $pol, string $pod)
    {
        return $query->whereHas('polPort', function($q) use ($pol) {
            $q->where('code', $pol);
        })->whereHas('podPort', function($q) use ($pod) {
            $q->where('code', $pod);
        });
    }

    public function scopeByServiceType($query, string $serviceType)
    {
        return $query->whereHas('carrier', function($q) use ($serviceType) {
            $q->whereJsonContains('service_types', $serviceType);
        });
    }

    public function scopeUpcomingSailings($query)
    {
        return $query->where('next_sailing_date', '>=', now());
    }

    public function getFrequencyDisplayAttribute(): string
    {
        if ($this->frequency_per_month) {
            if ($this->frequency_per_month == 1) {
                return '1x/month';
            } elseif ($this->frequency_per_month == 2) {
                return '2x/month';
            } elseif ($this->frequency_per_month == 4) {
                return 'Weekly';
            } else {
                return number_format($this->frequency_per_month, 1) . 'x/month';
            }
        }
        return 'On request';
    }

    public function getTransitTimeDisplayAttribute(): string
    {
        return $this->transit_days ? $this->transit_days . ' days' : 'TBA';
    }
}