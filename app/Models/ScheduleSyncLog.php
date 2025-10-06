<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ScheduleSyncLog extends Model
{
    protected $fillable = [
        'sync_type',
        'schedules_updated',
        'carriers_processed',
        'status',
        'error_message',
        'details',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    /**
     * Get the latest successful sync
     */
    public static function getLatestSync()
    {
        return self::where('status', 'success')
                  ->whereNotNull('completed_at')
                  ->orderBy('completed_at', 'desc')
                  ->first();
    }

    /**
     * Get the last sync timestamp
     */
    public static function getLastSyncTime()
    {
        $latest = self::getLatestSync();
        return $latest ? $latest->completed_at : null;
    }

    /**
     * Check if sync is currently running
     */
    public static function isSyncRunning()
    {
        return self::whereNull('completed_at')
                  ->where('started_at', '>', now()->subHours(1)) // Only check recent syncs
                  ->exists();
    }

    /**
     * Get sync duration
     */
    public function getDurationAttribute()
    {
        if (!$this->completed_at) {
            return null;
        }
        
        return $this->started_at->diffForHumans($this->completed_at, true);
    }

    /**
     * Get formatted last sync time
     */
    public static function getFormattedLastSyncTime()
    {
        $lastSync = self::getLastSyncTime();
        
        if (!$lastSync) {
            return 'Never synced';
        }
        
        return $lastSync->format('M j, Y \a\t g:i A');
    }
}
