<?php

namespace App\Models;

use App\Models\Concerns\HasMultiScopeMatches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarrierArticleMapping extends Model
{
    use HasFactory, HasMultiScopeMatches;

    protected $fillable = [
        'carrier_id',
        'article_id',
        'name',
        'port_ids',
        'port_group_ids',
        'vehicle_categories',
        'category_group_ids',
        'vessel_names',
        'vessel_classes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'port_ids' => 'array',
        'port_group_ids' => 'array',
        'vehicle_categories' => 'array',
        'category_group_ids' => 'array',
        'vessel_names' => 'array',
        'vessel_classes' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Normalize empty arrays to NULL before saving
     */
    protected static function booted(): void
    {
        static::saving(function ($model) {
            foreach (['port_ids', 'port_group_ids', 'vehicle_categories', 'category_group_ids', 'vessel_names', 'vessel_classes'] as $field) {
                if (isset($model->attributes[$field])) {
                    $value = $model->attributes[$field];
                    // If it's a JSON string (after cast encoding), decode it first
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        if (empty($decoded)) {
                            $model->attributes[$field] = null;
                        }
                    } elseif (empty($value)) {
                        $model->attributes[$field] = null;
                    }
                }
            }
            
            // Validate that article's carrier matches mapping's carrier
            if ($model->article_id && $model->carrier_id) {
                $article = RobawsArticleCache::find($model->article_id);
                if ($article && $article->shipping_carrier_id !== null) {
                    // Article has a specific carrier - it must match the mapping's carrier
                    if ($article->shipping_carrier_id != $model->carrier_id) {
                        $articleCode = $article->article_code ?? 'N/A';
                        $articleName = $article->article_name ?? 'Unknown';
                        $articleCarrier = ShippingCarrier::find($article->shipping_carrier_id);
                        $mappingCarrier = ShippingCarrier::find($model->carrier_id);
                        
                        $mappingCarrierName = $mappingCarrier?->name ?? $model->carrier_id;
                        $articleCarrierName = $articleCarrier?->name ?? $article->shipping_carrier_id;
                        
                        throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                            "Cannot map article '{$articleCode}' ({$articleName}) to carrier '{$mappingCarrierName}'. " .
                            "Article belongs to carrier '{$articleCarrierName}'. " .
                            "Universal articles (no carrier) can be mapped to any carrier."
                        );
                    }
                }
                // If article has null shipping_carrier_id, it's universal and can be mapped to any carrier - no validation needed
            }
        });
        
        // After mapping is saved, update article fields to ensure it shows in quotations
        static::saved(function ($model) {
            if ($model->article_id && $model->carrier_id) {
                $article = RobawsArticleCache::find($model->article_id);
                if ($article) {
                    $needsUpdate = false;
                    
                    // Ensure carrier_id is set
                    if ($article->shipping_carrier_id !== $model->carrier_id) {
                        $article->shipping_carrier_id = $model->carrier_id;
                        $needsUpdate = true;
                    }
                    
                    // Ensure validity_date is set and valid (at least 1 year from now)
                    $oneYearFromNow = now()->addYear();
                    if (!$article->validity_date || $article->validity_date < now()) {
                        $article->validity_date = $oneYearFromNow->format('Y-m-d');
                        $needsUpdate = true;
                    }
                    
                    // Ensure transport_mode is set if not already
                    if (!$article->transport_mode && $model->carrier_id) {
                        // Default to RORO for Grimaldi, or infer from article name
                        $carrier = ShippingCarrier::find($model->carrier_id);
                        if ($carrier && stripos($carrier->name ?? '', 'grimaldi') !== false) {
                            $article->transport_mode = 'RORO';
                            $needsUpdate = true;
                        }
                    }
                    
                    // Ensure service_type is set if not already
                    if (!$article->service_type && $article->transport_mode) {
                        $article->service_type = strtoupper($article->transport_mode . '_EXPORT');
                        $needsUpdate = true;
                    }
                    
                    // Update POD field if mapping has specific port_ids (single port)
                    // This ensures the article shows up in quotations for that port
                    // POD must be strict - article POD must match quotation POD
                    // When mapping to a specific port, update the article's POD to match that port
                    if (!empty($model->port_ids) && is_array($model->port_ids) && count($model->port_ids) === 1) {
                        // Single port mapping - update article POD to match that port
                        $portId = $model->port_ids[0];
                        $port = \App\Models\Port::find($portId);
                        if ($port) {
                            // Format POD as "City (CODE), Country" to match Robaws format
                            // Use country_name if available, otherwise country_code
                            $podString = $port->name;
                            if ($port->code) {
                                $podString .= ' (' . $port->code . ')';
                            }
                            if ($port->country_name) {
                                $podString .= ', ' . $port->country_name;
                            } elseif ($port->country_code) {
                                $podString .= ', ' . $port->country_code;
                            }
                            
                            // Always update POD to match the mapped port (strict POD matching)
                            if ($article->pod !== $podString) {
                                $article->pod = $podString;
                                $needsUpdate = true;
                            }
                        }
                    }
                    // Note: For multi-port mappings (port_group_ids or multiple port_ids),
                    // we don't update POD as the article should remain universal for that group
                    
                    if ($needsUpdate) {
                        $article->saveQuietly();
                        \Log::info('CarrierArticleMapping: Updated article fields after mapping creation', [
                            'mapping_id' => $model->id,
                            'article_id' => $article->id,
                            'carrier_id' => $article->shipping_carrier_id,
                            'validity_date' => $article->validity_date,
                            'transport_mode' => $article->transport_mode,
                            'service_type' => $article->service_type,
                            'pod' => $article->pod,
                        ]);
                    }
                }
            }
        });
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class);
    }

    public function purchaseTariffs(): HasMany
    {
        return $this->hasMany(CarrierPurchaseTariff::class)
            ->orderBy('sort_order', 'asc')
            ->orderBy('effective_from', 'asc');
    }

    public function activePurchaseTariff(): ?CarrierPurchaseTariff
    {
        // If relationship is eager-loaded, filter in memory to avoid N+1 queries
        // and ensure we use the same tariffs that were eager-loaded with surcharges
        if ($this->relationLoaded('purchaseTariffs')) {
            $now = \Carbon\Carbon::now();
            $filtered = $this->purchaseTariffs
                ->filter(function ($tariff) use ($now) {
                    // Apply active() scope logic in memory
                    if (!$tariff->is_active) {
                        return false;
                    }
                    if ($tariff->effective_from && $tariff->effective_from > $now) {
                        return false;
                    }
                    if ($tariff->effective_to && $tariff->effective_to < $now) {
                        return false;
                    }
                    return true;
                })
                ->sortBy([
                    ['effective_from', 'desc'],
                    ['sort_order', 'asc'],
                ]);
            
            return $filtered->first();
        }
        
        // Fallback to query if not eager-loaded
        // Get the most recent active tariff (effective_from DESC, then sort_order ASC)
        return $this->purchaseTariffs()
            ->active()
            ->orderBy('effective_from', 'desc')
            ->orderBy('sort_order', 'asc')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

