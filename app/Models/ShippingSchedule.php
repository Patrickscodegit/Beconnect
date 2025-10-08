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
        'voyage_number',
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
            $freq = (float) $this->frequency_per_month;
            
            if ($freq >= 4.0) {
                return 'Weekly service';
            } elseif ($freq >= 2.5) {
                return '2-3x/month';
            } elseif ($freq >= 1.5) {
                return 'Bi-weekly service';
            } elseif ($freq >= 0.8) {
                return 'Monthly service';
            } elseif ($freq >= 0.5) {
                return '~1x/month';
            } else {
                return 'Irregular service';
            }
        }
        return 'On request';
    }

    public function getTransitTimeDisplayAttribute(): string
    {
        return $this->transit_days ? $this->transit_days . ' days' : 'TBA';
    }

    /**
     * Calculate dynamic frequency for this schedule's route
     */
    public function getDynamicFrequency(int $monthsAhead = 6): array
    {
        $frequencyService = app(\App\Services\ScheduleFrequencyCalculationService::class);
        return $frequencyService->calculateScheduleFrequency($this, $monthsAhead);
    }

    /**
     * Get dynamic frequency display text
     */
    public function getDynamicFrequencyDisplayAttribute(): string
    {
        $frequencyService = app(\App\Services\ScheduleFrequencyCalculationService::class);
        $frequencyData = $this->getDynamicFrequency();
        return $frequencyService->getFrequencyDisplayText($frequencyData);
    }

    /**
     * Get the most accurate frequency for display
     * Uses dynamic calculation if available, falls back to stored values
     */
    public function getAccurateFrequencyDisplayAttribute(): string
    {
        try {
            $dynamicFreq = $this->getDynamicFrequencyDisplayAttribute();
            
            // Only use dynamic if it's based on actual data
            $frequencyData = $this->getDynamicFrequency();
            if ($frequencyData['is_dynamic'] && $frequencyData['total_sailings'] > 0) {
                return $dynamicFreq;
            }
        } catch (\Exception $e) {
            // Fall back to stored frequency if dynamic calculation fails
            \Log::warning('Dynamic frequency calculation failed', [
                'schedule_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback to stored frequency
        return $this->getFrequencyDisplayAttribute();
    }
}