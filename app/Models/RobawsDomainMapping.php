<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RobawsDomainMapping extends Model
{
    protected $table = 'robaws_domain_mappings';

    protected $fillable = [
        'domain',
        'robaws_client_id',
        'label',
    ];
}
