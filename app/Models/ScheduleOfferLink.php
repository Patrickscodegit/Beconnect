<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleOfferLink extends Model
{
    protected $fillable = [
        'shipping_schedule_id',
        'robaws_offer_id',
        'selected_articles',
        'linked_by',
        'linked_at',
    ];

    protected $casts = [
        'selected_articles' => 'array',
        'linked_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($link) {
            if (empty($link->linked_at)) {
                $link->linked_at = now();
            }
        });
    }

    /**
     * Relationships
     */
    public function shippingSchedule(): BelongsTo
    {
        return $this->belongsTo(ShippingSchedule::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /**
     * Get article count
     */
    public function getArticleCountAttribute(): int
    {
        return count($this->selected_articles ?? []);
    }
}
