<?php

namespace App\Jobs;

use App\Models\ScheduleUpdatesLog;
use App\Models\ScheduleSyncLog;
use App\Services\ScheduleExtraction\ScheduleExtractionPipeline;
use App\Services\ScheduleExtraction\RealNmtScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealGrimaldiScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealWalleniusWilhelmsenScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealSallaumScheduleExtractionStrategy;
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
        
        // Use REAL DATA extraction strategies only - no mock data!
        $pipeline->addStrategy(new RealSallaumScheduleExtractionStrategy()); // West Africa specialist
        $pipeline->addStrategy(new RealNmtScheduleExtractionStrategy());
        $pipeline->addStrategy(new RealGrimaldiScheduleExtractionStrategy());
        $pipeline->addStrategy(new RealWalleniusWilhelmsenScheduleExtractionStrategy());
        
        Log::info('Registered REAL DATA schedule extraction strategies (no mock data)');
        
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
        // Only use the 3 required POLs: Antwerp, Zeebrugge, Flushing
        $pols = ['ANR', 'ZEE', 'FLU'];
        
        // Sallaum Lines - West Africa routes (REAL DATA ONLY)
        $sallaumPods = [
            'LOS', // Lagos, Nigeria
            'DKR', // Dakar, Senegal
            'ABJ', // Abidjan, Côte d'Ivoire
            'TEM', // Tema, Ghana
            'CKY', // Conakry, Guinea
            'LFW', // Lomé, Togo
            'COO', // Cotonou, Benin
        ];
        
        $combinations = [];
        
        // Generate all combinations of the 3 POLs with Sallaum West Africa PODs
        foreach ($pols as $pol) {
            foreach ($sallaumPods as $pod) {
                $combinations[] = ['pol' => $pol, 'pod' => $pod];
            }
        }
        
        return $combinations;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateShippingSchedulesJob failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}