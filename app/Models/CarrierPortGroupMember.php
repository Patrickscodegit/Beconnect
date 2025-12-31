<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierPortGroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_port_group_id',
        'port_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function portGroup(): BelongsTo
    {
        return $this->belongsTo(CarrierPortGroup::class, 'carrier_port_group_id');
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }
}
