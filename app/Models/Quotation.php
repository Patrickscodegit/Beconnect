<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'document_id',
        'robaws_id',
        'quotation_number',
        'status',
        'client_name',
        'client_email',
        'client_phone',
        'origin_port',
        'destination_port',
        'cargo_type',
        'container_type',
        'weight',
        'volume',
        'pieces',
        'estimated_cost',
        'currency',
        'valid_until',
        'robaws_data',
        'auto_created',
        'created_from_document',
        'sent_at',
        'accepted_at',
        'rejected_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'volume' => 'float',
        'pieces' => 'integer',
        'estimated_cost' => 'decimal:2',
        'robaws_data' => 'array',
        'auto_created' => 'boolean',
        'created_from_document' => 'boolean',
        'valid_until' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('valid_until', '>', now());
    }

    public function scopeAutoCreated($query)
    {
        return $query->where('auto_created', true);
    }

    // Accessors
    public function getFormattedCostAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->estimated_cost, 2);
    }

    public function getIsValidAttribute(): bool
    {
        return $this->valid_until > now() && in_array($this->status, ['draft', 'sent', 'active']);
    }

    public function getRobawsUrlAttribute(): string
    {
        return config('services.robaws.base_url') . '/offers/' . $this->robaws_id;
    }
}
