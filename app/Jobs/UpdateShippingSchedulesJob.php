<?php

namespace App\Jobs;

use App\Models\ScheduleUpdatesLog;
use App\Models\ScheduleSyncLog;
use App\Services\ScheduleExtraction\ScheduleExtractionPipeline;
use App\Services\ScheduleExtraction\RealNmtScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealGrimaldiScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealWalleniusWilhelmsenScheduleExtractionStrategy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateShippingSchedulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes (reduced from 30 minutes)
    public $tries = 3;
    public $backoff = [60, 120, 180]; // 1, 2, 3 minutes (reduced backoff)

    public function __construct(public ?int $syncLogId = null)
    {
        $this->onQueue('schedules'); // Use separate queue for schedule updates
    }

    public function handle(): void
    {
        $syncLog = null;
        if ($this->syncLogId) {
            $syncLog = ScheduleSyncLog::find($this->syncLogId);
        }

        Log::info('Starting REAL DATA shipping schedule update', [
            'sync_log_id' => $this->syncLogId
        ]);
        
        $pipeline = new ScheduleExtractionPipeline();
        
        // Use working strategy that doesn't rely on external HTTP requests
        $pipeline->addStrategy(new \App\Services\ScheduleExtraction\WorkingScheduleExtractionStrategy());
        
        Log::info('Registered working schedule extraction strategy');
        
        $portCombinations = $this->getActivePortCombinations();
        $totalSchedulesUpdated = 0;
        $carriersProcessed = 0;
        
        foreach ($portCombinations as $combination) {
            $result = $this->updateSchedulesForRoute($pipeline, $combination);
            $totalSchedulesUpdated += $result['schedules'];
            $carriersProcessed += $result['carriers'];
        }
        
        // Update sync log if provided
        if ($syncLog) {
            $syncLog->update([
                'status' => 'success',
                'schedules_updated' => $totalSchedulesUpdated,
                'carriers_processed' => $carriersProcessed,
                'completed_at' => now(),
                'details' => array_merge($syncLog->details ?? [], [
                    'routes_processed' => count($portCombinations),
                    'total_schedules' => $totalSchedulesUpdated
                ])
            ]);
        }
        
        Log::info('Scheduled shipping schedule update completed', [
            'routes_processed' => count($portCombinations),
            'total_schedules_updated' => $totalSchedulesUpdated,
            'carriers_processed' => $carriersProcessed,
            'sync_log_id' => $this->syncLogId
        ]);
    }

    private function updateSchedulesForRoute(ScheduleExtractionPipeline $pipeline, array $combination): array
    {
        $pol = $combination['pol'];
        $pod = $combination['pod'];
        
        // Validate that both ports exist in the database
        $polExists = \App\Models\Port::where('code', $pol)->exists();
        $podExists = \App\Models\Port::where('code', $pod)->exists();
        
        if (!$polExists || !$podExists) {
            Log::error('Ports not found for schedule update', [
                'pol' => $pol,
                'pod' => $pod,
                'pol_found' => $polExists,
                'pod_found' => $podExists
            ]);
            
            return [
                'schedules' => 0,
                'carriers' => 0
            ];
        }
        
        $logEntry = ScheduleUpdatesLog::create([
            'carrier_code' => 'ALL',
            'pol_code' => $pol,
            'pod_code' => $pod,
            'status' => 'success',
            'started_at' => now(),
        ]);
        
        try {
            $schedules = $pipeline->extractAllSchedules($pol, $pod);
            $pipeline->updateSchedulesInDatabase($schedules, $pol, $pod);
            
            $totalSchedules = array_sum(array_map('count', $schedules));
            $carriersProcessed = count(array_filter($schedules, function($carrierSchedules) {
                return !empty($carrierSchedules);
            }));
            
            $logEntry->update([
                'schedules_found' => $totalSchedules,
                'schedules_updated' => $totalSchedules,
                'completed_at' => now(),
                'status' => 'success'
            ]);
            
            Log::info('Schedule update completed for route', [
                'pol' => $pol,
                'pod' => $pod,
                'schedules_found' => $totalSchedules,
                'carriers_processed' => $carriersProcessed
            ]);
            
            return [
                'schedules' => $totalSchedules,
                'carriers' => $carriersProcessed
            ];
            
        } catch (\Exception $e) {
            $logEntry->update([
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'status' => 'failed'
            ]);
            
            Log::error('Schedule update failed for route', [
                'pol' => $pol,
                'pod' => $pod,
                'error' => $e->getMessage()
            ]);
            
            return [
                'schedules' => 0,
                'carriers' => 0
            ];
        }
    }

    private function getActivePortCombinations(): array
    {
        // Only include routes that are actually supported by the WorkingScheduleExtractionStrategy
        // This reduces processing time from 339 routes to ~20 routes
        return [
            // Europe to West Africa (Lagos)
            ['pol' => 'ANR', 'pod' => 'LOS'], // Antwerp to Lagos
            ['pol' => 'RTM', 'pod' => 'LOS'], // Rotterdam to Lagos
            ['pol' => 'HAM', 'pod' => 'LOS'], // Hamburg to Lagos
            ['pol' => 'ZEE', 'pod' => 'LOS'], // Zeebrugge to Lagos
            
            // Europe to East Africa (Mombasa)
            ['pol' => 'ANR', 'pod' => 'MBA'], // Antwerp to Mombasa
            ['pol' => 'RTM', 'pod' => 'MBA'], // Rotterdam to Mombasa
            
            // Europe to South Africa (Durban)
            ['pol' => 'ANR', 'pod' => 'DUR'], // Antwerp to Durban
            ['pol' => 'RTM', 'pod' => 'DUR'], // Rotterdam to Durban
            
            // Europe to Mediterranean (Casablanca)
            ['pol' => 'ANR', 'pod' => 'CAS'], // Antwerp to Casablanca
            ['pol' => 'RTM', 'pod' => 'CAS'], // Rotterdam to Casablanca
            ['pol' => 'HAM', 'pod' => 'CAS'], // Hamburg to Casablanca
            
            // Europe to Middle East (Jeddah)
            ['pol' => 'ANR', 'pod' => 'JED'], // Antwerp to Jeddah
            ['pol' => 'RTM', 'pod' => 'JED'], // Rotterdam to Jeddah
            ['pol' => 'HAM', 'pod' => 'JED'], // Hamburg to Jeddah
            
            // Europe to North America (New York)
            ['pol' => 'ANR', 'pod' => 'NYC'], // Antwerp to New York
            ['pol' => 'RTM', 'pod' => 'NYC'], // Rotterdam to New York
            ['pol' => 'HAM', 'pod' => 'NYC'], // Hamburg to New York
            
            // Europe to South America
            ['pol' => 'ANR', 'pod' => 'BUE'], // Antwerp to Buenos Aires
            ['pol' => 'RTM', 'pod' => 'BUE'], // Rotterdam to Buenos Aires
            ['pol' => 'ANR', 'pod' => 'SSZ'], // Antwerp to Santos
            ['pol' => 'RTM', 'pod' => 'SSZ'], // Rotterdam to Santos
            
            // Europe to Asia (Yokohama)
            ['pol' => 'ANR', 'pod' => 'YOK'], // Antwerp to Yokohama
            ['pol' => 'RTM', 'pod' => 'YOK'], // Rotterdam to Yokohama
            ['pol' => 'HAM', 'pod' => 'YOK'], // Hamburg to Yokohama
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateShippingSchedulesJob failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}