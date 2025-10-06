<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleUpdatesLog extends Model
{
    use HasFactory;

    protected $table = 'schedule_updates_log';

    protected $fillable = [
        'carrier_code',
        'pol_code',
        'pod_code',
        'schedules_found',
        'schedules_updated',
        'schedules_created',
        'error_message',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'schedules_found' => 'integer',
        'schedules_updated' => 'integer',
        'schedules_created' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    public function getDurationAttribute(): ?int
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInSeconds($this->completed_at);
        }
        return null;
    }

    public function getDurationDisplayAttribute(): string
    {
        $duration = $this->duration;
        if (!$duration) return 'N/A';
        
        if ($duration < 60) return $duration . 's';
        if ($duration < 3600) return round($duration / 60) . 'm';
        return round($duration / 3600, 1) . 'h';
    }
}