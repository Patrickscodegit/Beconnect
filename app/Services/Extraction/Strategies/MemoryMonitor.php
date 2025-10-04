<?php

namespace App\Services\Extraction\Strategies;

use Illuminate\Support\Facades\Log;

/**
 * MEMORY MONITOR
 * 
 * Monitors memory usage during PDF processing and provides optimization insights.
 * Tracks peak memory usage and provides warnings when limits are exceeded.
 */
class MemoryMonitor
{
    private int $startMemory;
    private int $peakMemory;
    private bool $monitoring = false;
    private array $memorySnapshots = [];
    private int $snapshotCount = 0;
    private int $memoryLimitMB;
    private int $warningThresholdMB;

    public function __construct(int $memoryLimitMB = 128, int $warningThresholdMB = 64)
    {
        $this->memoryLimitMB = $memoryLimitMB;
        $this->warningThresholdMB = $warningThresholdMB;
        $this->startMemory = memory_get_usage(true);
        $this->peakMemory = $this->startMemory;
    }

    /**
     * Start monitoring memory usage
     */
    public function startMonitoring(): void
    {
        $this->monitoring = true;
        $this->startMemory = memory_get_usage(true);
        $this->peakMemory = $this->startMemory;
        $this->memorySnapshots = [];
        $this->snapshotCount = 0;
        
        Log::debug('Memory monitoring started', [
            'start_memory_mb' => $this->bytesToMB($this->startMemory),
            'limit_mb' => $this->memoryLimitMB,
            'warning_threshold_mb' => $this->warningThresholdMB
        ]);
    }

    /**
     * Stop monitoring memory usage
     */
    public function stopMonitoring(): void
    {
        if (!$this->monitoring) {
            return;
        }
        
        $this->monitoring = false;
        $currentMemory = memory_get_usage(true);
        $peakMemoryMB = $this->bytesToMB($this->peakMemory);
        $currentMemoryMB = $this->bytesToMB($currentMemory);
        $startMemoryMB = $this->bytesToMB($this->startMemory);
        
        // Log memory usage summary
        Log::info('Memory monitoring stopped', [
            'start_memory_mb' => $startMemoryMB,
            'peak_memory_mb' => $peakMemoryMB,
            'current_memory_mb' => $currentMemoryMB,
            'memory_increase_mb' => $currentMemoryMB - $startMemoryMB,
            'snapshots_taken' => $this->snapshotCount
        ]);
        
        // Check for memory warnings
        $this->checkMemoryWarnings($peakMemoryMB);
    }

    /**
     * Take a memory snapshot
     */
    public function takeSnapshot(string $label = ''): void
    {
        if (!$this->monitoring) {
            return;
        }
        
        $currentMemory = memory_get_usage(true);
        $this->peakMemory = max($this->peakMemory, $currentMemory);
        
        $snapshot = [
            'label' => $label ?: 'snapshot_' . ($this->snapshotCount + 1),
            'memory_bytes' => $currentMemory,
            'memory_mb' => $this->bytesToMB($currentMemory),
            'peak_memory_mb' => $this->bytesToMB($this->peakMemory),
            'timestamp' => microtime(true)
        ];
        
        $this->memorySnapshots[] = $snapshot;
        $this->snapshotCount++;
        
        Log::debug('Memory snapshot taken', $snapshot);
    }

    /**
     * Get current memory usage
     */
    public function getCurrentMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    /**
     * Get peak memory usage
     */
    public function getPeakMemoryUsage(): int
    {
        return $this->peakMemory;
    }

    /**
     * Get memory usage in MB
     */
    public function getCurrentMemoryUsageMB(): float
    {
        return $this->bytesToMB($this->getCurrentMemoryUsage());
    }

    /**
     * Get peak memory usage in MB
     */
    public function getPeakMemoryUsageMB(): float
    {
        return $this->bytesToMB($this->getPeakMemoryUsage());
    }

    /**
     * Get memory increase since monitoring started
     */
    public function getMemoryIncrease(): int
    {
        return $this->getCurrentMemoryUsage() - $this->startMemory;
    }

    /**
     * Get memory increase in MB
     */
    public function getMemoryIncreaseMB(): float
    {
        return $this->bytesToMB($this->getMemoryIncrease());
    }

    /**
     * Check if memory usage is within limits
     */
    public function isWithinLimits(): bool
    {
        $currentMemoryMB = $this->getCurrentMemoryUsageMB();
        return $currentMemoryMB <= $this->memoryLimitMB;
    }

    /**
     * Check if memory usage exceeds warning threshold
     */
    public function exceedsWarningThreshold(): bool
    {
        $currentMemoryMB = $this->getCurrentMemoryUsageMB();
        return $currentMemoryMB > $this->warningThresholdMB;
    }

    /**
     * Force garbage collection
     */
    public function forceGarbageCollection(): int
    {
        $beforeMemory = $this->getCurrentMemoryUsage();
        $collected = gc_collect_cycles();
        $afterMemory = $this->getCurrentMemoryUsage();
        
        $memoryFreed = $beforeMemory - $afterMemory;
        
        Log::debug('Garbage collection performed', [
            'cycles_collected' => $collected,
            'memory_freed_bytes' => $memoryFreed,
            'memory_freed_mb' => $this->bytesToMB($memoryFreed),
            'memory_before_mb' => $this->bytesToMB($beforeMemory),
            'memory_after_mb' => $this->bytesToMB($afterMemory)
        ]);
        
        return $collected;
    }

    /**
     * Get memory statistics
     */
    public function getStatistics(): array
    {
        $currentMemory = $this->getCurrentMemoryUsage();
        $peakMemory = $this->getPeakMemoryUsage();
        
        return [
            'monitoring' => $this->monitoring,
            'start_memory_mb' => $this->bytesToMB($this->startMemory),
            'current_memory_mb' => $this->bytesToMB($currentMemory),
            'peak_memory_mb' => $this->bytesToMB($peakMemory),
            'memory_increase_mb' => $this->bytesToMB($currentMemory - $this->startMemory),
            'memory_limit_mb' => $this->memoryLimitMB,
            'warning_threshold_mb' => $this->warningThresholdMB,
            'within_limits' => $this->isWithinLimits(),
            'exceeds_warning' => $this->exceedsWarningThreshold(),
            'snapshots_taken' => $this->snapshotCount,
            'snapshots' => $this->memorySnapshots
        ];
    }

    /**
     * Get memory usage trend
     */
    public function getMemoryTrend(): array
    {
        if (count($this->memorySnapshots) < 2) {
            return ['trend' => 'insufficient_data', 'snapshots' => count($this->memorySnapshots)];
        }
        
        $firstSnapshot = $this->memorySnapshots[0];
        $lastSnapshot = end($this->memorySnapshots);
        
        $memoryIncrease = $lastSnapshot['memory_mb'] - $firstSnapshot['memory_mb'];
        $timeIncrease = $lastSnapshot['timestamp'] - $firstSnapshot['timestamp'];
        
        $trend = 'stable';
        if ($memoryIncrease > 10) {
            $trend = 'increasing';
        } elseif ($memoryIncrease < -10) {
            $trend = 'decreasing';
        }
        
        return [
            'trend' => $trend,
            'memory_increase_mb' => round($memoryIncrease, 2),
            'time_increase_seconds' => round($timeIncrease, 2),
            'snapshots_analyzed' => count($this->memorySnapshots)
        ];
    }

    /**
     * Check for memory warnings and log them
     */
    private function checkMemoryWarnings(float $peakMemoryMB): void
    {
        if ($peakMemoryMB > $this->memoryLimitMB) {
            Log::warning('Memory limit exceeded', [
                'peak_memory_mb' => $peakMemoryMB,
                'limit_mb' => $this->memoryLimitMB,
                'excess_mb' => $peakMemoryMB - $this->memoryLimitMB
            ]);
        } elseif ($peakMemoryMB > $this->warningThresholdMB) {
            Log::info('Memory usage high but within limits', [
                'peak_memory_mb' => $peakMemoryMB,
                'warning_threshold_mb' => $this->warningThresholdMB,
                'limit_mb' => $this->memoryLimitMB
            ]);
        }
    }

    /**
     * Convert bytes to MB
     */
    private function bytesToMB(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }

    /**
     * Set memory limit
     */
    public function setMemoryLimit(int $memoryLimitMB): void
    {
        $this->memoryLimitMB = $memoryLimitMB;
        
        Log::debug('Memory limit updated', [
            'new_limit_mb' => $memoryLimitMB
        ]);
    }

    /**
     * Set warning threshold
     */
    public function setWarningThreshold(int $warningThresholdMB): void
    {
        $this->warningThresholdMB = $warningThresholdMB;
        
        Log::debug('Warning threshold updated', [
            'new_threshold_mb' => $warningThresholdMB
        ]);
    }

    /**
     * Get memory snapshots
     */
    public function getMemorySnapshots(): array
    {
        return $this->memorySnapshots;
    }

    /**
     * Clear memory snapshots
     */
    public function clearSnapshots(): void
    {
        $this->memorySnapshots = [];
        $this->snapshotCount = 0;
        
        Log::debug('Memory snapshots cleared');
    }

    /**
     * Check if monitoring is active
     */
    public function isMonitoring(): bool
    {
        return $this->monitoring;
    }
}
