<?php

namespace App\Models;

use App\Enums\ServiceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationAdditionalService extends Model
{
    protected $table = 'quotation_additional_services';

    protected $fillable = [
        'quotation_request_id',
        'robaws_article_cache_id',
        'service_category',
        'is_mandatory',
        'is_selected',
        'quantity',
        'unit_price',
        'total_price',
        'notes',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'is_selected' => 'boolean',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->quantity = $model->quantity ?? 0;
            $model->unit_price = $model->unit_price ?? 0;
            $model->total_price = (float) $model->quantity * (float) $model->unit_price;
        });
    }

    public function quotationRequest(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class, 'robaws_article_cache_id');
    }

    public function getServiceCategoryEnumAttribute(): ?ServiceCategory
    {
        return $this->service_category
            ? ServiceCategory::tryFrom($this->service_category)
            : null;
    }

    public function getComputedTotalAttribute(): float
    {
        $quantity = $this->quantity ?? 0;
        $unitPrice = $this->unit_price ?? 0;

        return (float) ($quantity * $unitPrice);
    }
}
