<?php

namespace App\Observers;

use App\Models\Port;
use Illuminate\Validation\ValidationException;

class PortObserver
{
    /**
     * Handle the Port "updating" event.
     * Block code changes if port is referenced.
     */
    public function updating(Port $port): void
    {
        if ($port->isDirty('code') && $port->isReferenced()) {
            throw ValidationException::withMessages([
                'code' => 'Port code is locked because it is referenced by schedules, mappings, or articles.'
            ]);
        }
    }
}

