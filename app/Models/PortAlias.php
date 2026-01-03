<?php

namespace App\Models;

use App\Support\PortAliasNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'port_id',
        'alias',
        'alias_normalized',
        'alias_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Auto-compute alias_normalized when alias is set
     * Uses shared PortAliasNormalizer for consistency
     */
    protected static function booted(): void
    {
        static::saving(function ($model) {
            if ($model->isDirty('alias') || empty($model->alias_normalized)) {
                $model->alias_normalized = PortAliasNormalizer::normalize($model->alias);
            }
        });
    }

    /**
     * Normalize alias: lowercase, trim, collapse whitespace
     * @deprecated Use PortAliasNormalizer::normalize() instead
     */
    public static function normalizeAlias(string $alias): string
    {
        return PortAliasNormalizer::normalize($alias);
    }

    /**
     * Relationship to Port
     */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /**
     * Scope: Active aliases only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Find by normalized alias
     */
    public function scopeByNormalized($query, string $normalized)
    {
        $normalized = PortAliasNormalizer::normalize($normalized);
        return $query->where('alias_normalized', $normalized);
    }
}

