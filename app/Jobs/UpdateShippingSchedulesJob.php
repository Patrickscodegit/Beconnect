<?php

namespace App\Jobs;

use App\Models\ScheduleUpdatesLog;
use App\Models\ScheduleSyncLog;
use App\Services\ScheduleExtraction\ScheduleExtractionPipeline;
use App\Services\ScheduleExtraction\RealNmtScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealGrimaldiScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealWalleniusWilhelmsenScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealSallaumScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\AIScheduleExtractionStrategy;
use App\Services\AI\AIScheduleValidationService;
use App\Services\AI\OpenAIService;
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
        // Temporarily disabled - run sync synchronously until Horizon is configured
        // TODO: Re-enable when Horizon is running in production
        // $this->onQueue('schedules');
    }

    public function handle(): void
    {
        $syncLog = null;
        if ($this->syncLogId) {
            $syncLog = ScheduleSyncLog::find($this->syncLogId);
        }

        try {
            Log::info('Starting AI-POWERED shipping schedule update', [
                'sync_log_id' => $this->syncLogId
            ]);
            
            // Initialize AI services for dynamic validation and parsing
            $aiValidator = new AIScheduleValidationService(new OpenAIService());
            $pipeline = new ScheduleExtractionPipeline($aiValidator);
            
            // Use REAL DATA extraction strategies only - no mock data!
            $pipeline->addStrategy(new RealSallaumScheduleExtractionStrategy()); // West Africa specialist
            $pipeline->addStrategy(new RealNmtScheduleExtractionStrategy());
            $pipeline->addStrategy(new RealGrimaldiScheduleExtractionStrategy());
            $pipeline->addStrategy(new RealWalleniusWilhelmsenScheduleExtractionStrategy());
            
            // Add AI parsing strategy if enabled
            // TEMPORARILY DISABLED: AI parsing hits rate limits and returns 0 schedules
            // Re-enable after optimizing API calls
            if (false && config('schedule_extraction.use_ai_parsing', false)) {
                $pipeline->addStrategy(new AIScheduleExtractionStrategy(new OpenAIService()));
                Log::info('AI parsing strategy enabled for dynamic schedule extraction');
            }
            
            Log::info('Registered AI-POWERED schedule extraction strategies (no hardcoded data)');
            
            $portCombinations = $this->getActivePortCombinations();
            $totalSchedulesUpdated = 0;
            $carriersProcessed = 0;
            
            foreach ($portCombinations as $combination) {
                try {
                    $result = $this->updateSchedulesForRoute($pipeline, $combination);
                    $totalSchedulesUpdated += $result['schedules'];
                    $carriersProcessed += $result['carriers'];
                } catch (\Exception $routeException) {
                    Log::error('Failed to process route', [
                        'pol' => $combination['pol'],
                        'pod' => $combination['pod'],
                        'error' => $routeException->getMessage()
                    ]);
                    // Continue with next route
                }
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
        } catch (\Exception $e) {
            // Update sync log with error
            if ($syncLog) {
                $syncLog->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                    'details' => array_merge($syncLog->details ?? [], [
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString()
                    ])
                ]);
            }

            Log::error('Schedule update job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'sync_log_id' => $this->syncLogId
            ]);

            // Re-throw to be caught by the controller
            throw $e;
        }
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
        // Sallaum Lines POLs (only Antwerp and Zeebrugge - no Flushing)
        // Flushing will be used for other carriers later
        $sallaumPols = ['ANR', 'ZEE'];
        
        // Sallaum Lines - ALL destinations (REAL DATA ONLY - verified from their schedule page)
        // Source: https://sallaumlines.com/schedules/europe-to-west-and-south-africa/
        $sallaumPods = [
            // West Africa (8 ports)
            'ABJ', // Abidjan, Côte d'Ivoire
            'CKY', // Conakry, Guinea
            'COO', // Cotonou, Benin
            'DKR', // Dakar, Senegal
            'DLA', // Douala, Cameroon
            'LOS', // Lagos, Nigeria
            'LFW', // Lomé, Togo
            'PNR', // Pointe Noire, Republic of Congo
            
            // East Africa (2 ports)
            'DAR', // Dar es Salaam, Tanzania
            'MBA', // Mombasa, Kenya
            
            // South Africa (4 ports)
            'DUR', // Durban, South Africa
            'ELS', // East London, South Africa
            'PLZ', // Port Elizabeth, South Africa
            'WVB', // Walvis Bay, Namibia
        ];
        
        $combinations = [];
        
        // Generate all combinations of Sallaum's 2 POLs with ALL 14 Sallaum PODs
        // 2 POLs × 14 PODs = 28 route combinations
        foreach ($sallaumPols as $pol) {
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