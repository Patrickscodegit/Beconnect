<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intake extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'flags',
        'source',
        'service_type',
        'notes',
        'priority',
        'robaws_offer_id',
        'robaws_offer_number',
        'robaws_client_id',
        'robaws_exported_at',
        'robaws_export_status',
        'extraction_data',
        'aggregated_extraction_data',
        'is_multi_document',
        'total_documents',
        'processed_documents',
        'export_payload_hash',
        'export_attempt_count',
        'last_export_error',
        'last_export_error_at',
        'customer_name',
        'contact_email',
        'contact_phone',
        'customer_role',
    ];

    protected $casts = [
        'notes' => 'array',
        'flags' => 'array',
        'extraction_data' => 'array',
        'aggregated_extraction_data' => 'array',
        'is_multi_document' => 'boolean',
        'total_documents' => 'integer',
        'processed_documents' => 'integer',
        'export_attempt_count' => 'integer',
        'robaws_offer_id' => 'integer',
        'robaws_exported_at' => 'datetime',
        'last_export_error_at' => 'datetime',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(Extraction::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(IntakeFile::class);
    }

    public function quotationRequest(): HasOne
    {
        return $this->hasOne(QuotationRequest::class, 'intake_id');
    }
    
    /**
     * Get the customer associated with this intake
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(RobawsCustomerCache::class, 'robaws_client_id', 'robaws_client_id');
    }
}
