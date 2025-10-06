<?php

namespace App\Jobs;

use App\Models\ScheduleUpdatesLog;
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

    public $timeout = 1800; // 30 minutes
    public $tries = 3;
    public $backoff = [300, 600, 900]; // 5, 10, 15 minutes

    public function __construct()
    {
        $this->onQueue('schedules'); // Use separate queue for schedule updates
    }

    public function handle(): void
    {
        Log::info('Starting REAL DATA shipping schedule update');
        
        $pipeline = new ScheduleExtractionPipeline();
        
        // Register ONLY real data extraction strategies
        // All mock data strategies have been removed
        $pipeline->addStrategy(new RealNmtScheduleExtractionStrategy());
        $pipeline->addStrategy(new RealGrimaldiScheduleExtractionStrategy());
        $pipeline->addStrategy(new RealWalleniusWilhelmsenScheduleExtractionStrategy());
        
        // TODO: Add more real data strategies as they are implemented
        // $pipeline->addStrategy(new RealHoeghAutolinersScheduleExtractionStrategy());
        // $pipeline->addStrategy(new RealSallaumScheduleExtractionStrategy());
        // etc.
        
        Log::info('Registered real data extraction strategies');
        
        $portCombinations = $this->getActivePortCombinations();
        
        foreach ($portCombinations as $combination) {
            $this->updateSchedulesForRoute($pipeline, $combination);
        }
        
        Log::info('Scheduled shipping schedule update completed', [
            'routes_processed' => count($portCombinations)
        ]);
    }

    private function updateSchedulesForRoute(ScheduleExtractionPipeline $pipeline, array $combination): void
    {
        $pol = $combination['pol'];
        $pod = $combination['pod'];
        
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
            
            $logEntry->update([
                'schedules_found' => $totalSchedules,
                'schedules_updated' => $totalSchedules,
                'completed_at' => now(),
                'status' => 'success'
            ]);
            
            Log::info('Schedule update completed for route', [
                'pol' => $pol,
                'pod' => $pod,
                'schedules_found' => $totalSchedules
            ]);
            
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
            
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    private function getActivePortCombinations(): array
    {
        // Comprehensive routes covering all major trade lanes
        return [
            // Europe to West Africa
            ['pol' => 'ANR', 'pod' => 'LOS'], // Antwerp to Lagos
            ['pol' => 'RTM', 'pod' => 'LOS'], // Rotterdam to Lagos
            ['pol' => 'HAM', 'pod' => 'LOS'], // Hamburg to Lagos
            ['pol' => 'BRV', 'pod' => 'LOS'], // Bremerhaven to Lagos
            ['pol' => 'ZEE', 'pod' => 'LOS'], // Zeebrugge to Lagos
            
            ['pol' => 'ANR', 'pod' => 'DKR'], // Antwerp to Dakar
            ['pol' => 'RTM', 'pod' => 'DKR'], // Rotterdam to Dakar
            ['pol' => 'HAM', 'pod' => 'DKR'], // Hamburg to Dakar
            ['pol' => 'BRV', 'pod' => 'DKR'], // Bremerhaven to Dakar
            ['pol' => 'ZEE', 'pod' => 'DKR'], // Zeebrugge to Dakar
            
            // Europe to East Africa
            ['pol' => 'ANR', 'pod' => 'MBA'], // Antwerp to Mombasa
            ['pol' => 'RTM', 'pod' => 'MBA'], // Rotterdam to Mombasa
            ['pol' => 'HAM', 'pod' => 'MBA'], // Hamburg to Mombasa
            ['pol' => 'BRV', 'pod' => 'MBA'], // Bremerhaven to Mombasa
            ['pol' => 'ZEE', 'pod' => 'MBA'], // Zeebrugge to Mombasa
            
            // Europe to South Africa
            ['pol' => 'ANR', 'pod' => 'DUR'], // Antwerp to Durban
            ['pol' => 'RTM', 'pod' => 'DUR'], // Rotterdam to Durban
            ['pol' => 'HAM', 'pod' => 'DUR'], // Hamburg to Durban
            ['pol' => 'BRV', 'pod' => 'DUR'], // Bremerhaven to Durban
            ['pol' => 'ZEE', 'pod' => 'DUR'], // Zeebrugge to Durban
            
            // Europe to Caribbean
            ['pol' => 'ANR', 'pod' => 'POS'], // Antwerp to Port of Spain
            ['pol' => 'RTM', 'pod' => 'POS'], // Rotterdam to Port of Spain
            ['pol' => 'HAM', 'pod' => 'POS'], // Hamburg to Port of Spain
            ['pol' => 'BRV', 'pod' => 'POS'], // Bremerhaven to Port of Spain
            ['pol' => 'FLU', 'pod' => 'POS'], // Flushing to Port of Spain
            
            ['pol' => 'ANR', 'pod' => 'BGI'], // Antwerp to Barbados
            ['pol' => 'RTM', 'pod' => 'BGI'], // Rotterdam to Barbados
            ['pol' => 'HAM', 'pod' => 'BGI'], // Hamburg to Barbados
            ['pol' => 'BRV', 'pod' => 'BGI'], // Bremerhaven to Barbados
            ['pol' => 'FLU', 'pod' => 'BGI'], // Flushing to Barbados
            
            // Europe to Asia
            ['pol' => 'ANR', 'pod' => 'YOK'], // Antwerp to Yokohama
            ['pol' => 'RTM', 'pod' => 'YOK'], // Rotterdam to Yokohama
            ['pol' => 'HAM', 'pod' => 'YOK'], // Hamburg to Yokohama
            ['pol' => 'BRV', 'pod' => 'YOK'], // Bremerhaven to Yokohama
            ['pol' => 'ZEE', 'pod' => 'YOK'], // Zeebrugge to Yokohama
            
            ['pol' => 'ANR', 'pod' => 'BUS'], // Antwerp to Busan
            ['pol' => 'RTM', 'pod' => 'BUS'], // Rotterdam to Busan
            ['pol' => 'HAM', 'pod' => 'BUS'], // Hamburg to Busan
            ['pol' => 'BRV', 'pod' => 'BUS'], // Bremerhaven to Busan
            ['pol' => 'ZEE', 'pod' => 'BUS'], // Zeebrugge to Busan
            
            ['pol' => 'ANR', 'pod' => 'SHA'], // Antwerp to Shanghai
            ['pol' => 'RTM', 'pod' => 'SHA'], // Rotterdam to Shanghai
            ['pol' => 'HAM', 'pod' => 'SHA'], // Hamburg to Shanghai
            ['pol' => 'BRV', 'pod' => 'SHA'], // Bremerhaven to Shanghai
            ['pol' => 'ZEE', 'pod' => 'SHA'], // Zeebrugge to Shanghai
            
            // Europe to Congo
            ['pol' => 'ANR', 'pod' => 'PNR'], // Antwerp to Pointe-Noire
            ['pol' => 'RTM', 'pod' => 'PNR'], // Rotterdam to Pointe-Noire
            ['pol' => 'HAM', 'pod' => 'PNR'], // Hamburg to Pointe-Noire
            ['pol' => 'BRV', 'pod' => 'PNR'], // Bremerhaven to Pointe-Noire
            
            ['pol' => 'ANR', 'pod' => 'MAT'], // Antwerp to Matadi
            ['pol' => 'RTM', 'pod' => 'MAT'], // Rotterdam to Matadi
            ['pol' => 'HAM', 'pod' => 'MAT'], // Hamburg to Matadi
            ['pol' => 'BRV', 'pod' => 'MAT'], // Bremerhaven to Matadi
            
            // Europe to Mediterranean
            ['pol' => 'ANR', 'pod' => 'CAS'], // Antwerp to Casablanca
            ['pol' => 'RTM', 'pod' => 'CAS'], // Rotterdam to Casablanca
            ['pol' => 'HAM', 'pod' => 'CAS'], // Hamburg to Casablanca
            ['pol' => 'BRV', 'pod' => 'CAS'], // Bremerhaven to Casablanca
            
            ['pol' => 'ANR', 'pod' => 'ALX'], // Antwerp to Alexandria
            ['pol' => 'RTM', 'pod' => 'ALX'], // Rotterdam to Alexandria
            ['pol' => 'HAM', 'pod' => 'ALX'], // Hamburg to Alexandria
            ['pol' => 'BRV', 'pod' => 'ALX'], // Bremerhaven to Alexandria
            
            ['pol' => 'ANR', 'pod' => 'PIR'], // Antwerp to Piraeus
            ['pol' => 'RTM', 'pod' => 'PIR'], // Rotterdam to Piraeus
            ['pol' => 'HAM', 'pod' => 'PIR'], // Hamburg to Piraeus
            ['pol' => 'BRV', 'pod' => 'PIR'], // Bremerhaven to Piraeus
            
            ['pol' => 'ANR', 'pod' => 'IST'], // Antwerp to Istanbul
            ['pol' => 'RTM', 'pod' => 'IST'], // Rotterdam to Istanbul
            ['pol' => 'HAM', 'pod' => 'IST'], // Hamburg to Istanbul
            ['pol' => 'BRV', 'pod' => 'IST'], // Bremerhaven to Istanbul
            
            ['pol' => 'ANR', 'pod' => 'BEY'], // Antwerp to Beirut
            ['pol' => 'RTM', 'pod' => 'BEY'], // Rotterdam to Beirut
            ['pol' => 'HAM', 'pod' => 'BEY'], // Hamburg to Beirut
            ['pol' => 'BRV', 'pod' => 'BEY'], // Bremerhaven to Beirut
            
            // Europe to North America
            ['pol' => 'ANR', 'pod' => 'NYC'], // Antwerp to New York
            ['pol' => 'RTM', 'pod' => 'NYC'], // Rotterdam to New York
            ['pol' => 'HAM', 'pod' => 'NYC'], // Hamburg to New York
            ['pol' => 'BRV', 'pod' => 'NYC'], // Bremerhaven to New York
            
            ['pol' => 'ANR', 'pod' => 'BAL'], // Antwerp to Baltimore
            ['pol' => 'RTM', 'pod' => 'BAL'], // Rotterdam to Baltimore
            ['pol' => 'HAM', 'pod' => 'BAL'], // Hamburg to Baltimore
            ['pol' => 'BRV', 'pod' => 'BAL'], // Bremerhaven to Baltimore
            
            ['pol' => 'ANR', 'pod' => 'MIA'], // Antwerp to Miami
            ['pol' => 'RTM', 'pod' => 'MIA'], // Rotterdam to Miami
            ['pol' => 'HAM', 'pod' => 'MIA'], // Hamburg to Miami
            ['pol' => 'BRV', 'pod' => 'MIA'], // Bremerhaven to Miami
            
            ['pol' => 'ANR', 'pod' => 'LAX'], // Antwerp to Los Angeles
            ['pol' => 'RTM', 'pod' => 'LAX'], // Rotterdam to Los Angeles
            ['pol' => 'HAM', 'pod' => 'LAX'], // Hamburg to Los Angeles
            ['pol' => 'BRV', 'pod' => 'LAX'], // Bremerhaven to Los Angeles
            
            // Europe to Asia (Additional destinations)
            ['pol' => 'ANR', 'pod' => 'SIN'], // Antwerp to Singapore
            ['pol' => 'RTM', 'pod' => 'SIN'], // Rotterdam to Singapore
            ['pol' => 'HAM', 'pod' => 'SIN'], // Hamburg to Singapore
            ['pol' => 'BRV', 'pod' => 'SIN'], // Bremerhaven to Singapore
            
            ['pol' => 'ANR', 'pod' => 'HKG'], // Antwerp to Hong Kong
            ['pol' => 'RTM', 'pod' => 'HKG'], // Rotterdam to Hong Kong
            ['pol' => 'HAM', 'pod' => 'HKG'], // Hamburg to Hong Kong
            ['pol' => 'BRV', 'pod' => 'HKG'], // Bremerhaven to Hong Kong
            
            ['pol' => 'ANR', 'pod' => 'BKK'], // Antwerp to Bangkok
            ['pol' => 'RTM', 'pod' => 'BKK'], // Rotterdam to Bangkok
            ['pol' => 'HAM', 'pod' => 'BKK'], // Hamburg to Bangkok
            ['pol' => 'BRV', 'pod' => 'BKK'], // Bremerhaven to Bangkok
            
            // Europe to Oceania (Additional destinations)
            ['pol' => 'ANR', 'pod' => 'MEL'], // Antwerp to Melbourne
            ['pol' => 'RTM', 'pod' => 'MEL'], // Rotterdam to Melbourne
            ['pol' => 'HAM', 'pod' => 'MEL'], // Hamburg to Melbourne
            ['pol' => 'BRV', 'pod' => 'MEL'], // Bremerhaven to Melbourne
            
            // Europe to South America
            ['pol' => 'ANR', 'pod' => 'BUE'], // Antwerp to Buenos Aires
            ['pol' => 'RTM', 'pod' => 'BUE'], // Rotterdam to Buenos Aires
            ['pol' => 'HAM', 'pod' => 'BUE'], // Hamburg to Buenos Aires
            ['pol' => 'BRV', 'pod' => 'BUE'], // Bremerhaven to Buenos Aires
            ['pol' => 'ZEE', 'pod' => 'BUE'], // Zeebrugge to Buenos Aires
            
            ['pol' => 'ANR', 'pod' => 'SSZ'], // Antwerp to Santos
            ['pol' => 'RTM', 'pod' => 'SSZ'], // Rotterdam to Santos
            ['pol' => 'HAM', 'pod' => 'SSZ'], // Hamburg to Santos
            ['pol' => 'BRV', 'pod' => 'SSZ'], // Bremerhaven to Santos
            ['pol' => 'ZEE', 'pod' => 'SSZ'], // Zeebrugge to Santos
            
            ['pol' => 'ANR', 'pod' => 'RIO'], // Antwerp to Rio de Janeiro
            ['pol' => 'RTM', 'pod' => 'RIO'], // Rotterdam to Rio de Janeiro
            ['pol' => 'HAM', 'pod' => 'RIO'], // Hamburg to Rio de Janeiro
            ['pol' => 'BRV', 'pod' => 'RIO'], // Bremerhaven to Rio de Janeiro
            ['pol' => 'ZEE', 'pod' => 'RIO'], // Zeebrugge to Rio de Janeiro
            
            ['pol' => 'ANR', 'pod' => 'MVD'], // Antwerp to Montevideo
            ['pol' => 'RTM', 'pod' => 'MVD'], // Rotterdam to Montevideo
            ['pol' => 'HAM', 'pod' => 'MVD'], // Hamburg to Montevideo
            ['pol' => 'BRV', 'pod' => 'MVD'], // Bremerhaven to Montevideo
            ['pol' => 'ZEE', 'pod' => 'MVD'], // Zeebrugge to Montevideo
            
            ['pol' => 'ANR', 'pod' => 'VAP'], // Antwerp to Valparaíso
            ['pol' => 'RTM', 'pod' => 'VAP'], // Rotterdam to Valparaíso
            ['pol' => 'HAM', 'pod' => 'VAP'], // Hamburg to Valparaíso
            ['pol' => 'BRV', 'pod' => 'VAP'], // Bremerhaven to Valparaíso
            ['pol' => 'ZEE', 'pod' => 'VAP'], // Zeebrugge to Valparaíso
            
            ['pol' => 'ANR', 'pod' => 'CAL'], // Antwerp to Callao
            ['pol' => 'RTM', 'pod' => 'CAL'], // Rotterdam to Callao
            ['pol' => 'HAM', 'pod' => 'CAL'], // Hamburg to Callao
            ['pol' => 'BRV', 'pod' => 'CAL'], // Bremerhaven to Callao
            ['pol' => 'ZEE', 'pod' => 'CAL'], // Zeebrugge to Callao
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