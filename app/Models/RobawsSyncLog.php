<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class RobawsSyncLog extends Model
{
    protected $fillable = [
        'sync_type',
        'items_synced',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'items_synced' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            if (empty($log->started_at)) {
                $log->started_at = now();
            }
        });
    }

    /**
     * Scopes
     */
    public function scopeForType(Builder $query, string $syncType): Builder
    {
        return $query->where('sync_type', $syncType);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('started_at', '>=', now()->subHours($hours));
    }

    /**
     * Mark sync as completed
     */
    public function markAsCompleted(int $itemsSynced): void
    {
        $this->update([
            'items_synced' => $itemsSynced,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark sync as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get duration in seconds
     */
    public function getDurationAttribute(): ?int
    {
        if (!$this->completed_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->completed_at);
    }

    /**
     * Get duration formatted
     */
    public function getDurationFormattedAttribute(): string
    {
        $duration = $this->duration;
        
        if ($duration === null) {
            return 'In progress...';
        }

        if ($duration < 60) {
            return "{$duration}s";
        }

        $minutes = floor($duration / 60);
        $seconds = $duration % 60;
        return "{$minutes}m {$seconds}s";
    }
}
