<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobawsCustomerPortalLink extends Model
{
    protected $table = 'robaws_customer_portal_links';

    protected $fillable = [
        'user_id',
        'robaws_client_id',
        'source',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
