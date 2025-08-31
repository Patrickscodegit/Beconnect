<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobawsDocument extends Model
{
    protected $fillable = [
        'document_id',
        'robaws_offer_id',
        'robaws_document_id',
        'sha256',
        'filename',
        'filesize',
    ];

    protected $casts = [
        'filesize' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
