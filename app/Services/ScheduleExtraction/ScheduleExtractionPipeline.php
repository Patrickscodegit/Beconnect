<?php

namespace App\Services\ScheduleExtraction;

use App\Models\ShippingCarrier;
use App\Models\Port;
use App\Models\ShippingSchedule;
use Illuminate\Support\Facades\Log;

class ScheduleExtractionPipeline
{
    private array $strategies = [];
    
    public function addStrategy(ScheduleExtractionStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getCarrierCode()] = $strategy;
    }
    
    public function extractAllSchedules(string $pol, string $pod): array
    {
        $results = [];
        
        foreach ($this->strategies as $carrierCode => $strategy) {
            try {
                if ($strategy->supports($pol, $pod)) {
                    $schedules = $strategy->extractSchedules($pol, $pod);
                    $results[$carrierCode] = $schedules;
                    
                    Log::info("Schedule extraction completed for {$carrierCode}", [
                        'pol' => $pol,
                        'pod' => $pod,
                        'schedules_found' => count($schedules)
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Schedule extraction failed for {$carrierCode}", [
                    'error' => $e->getMessage(),
                    'pol' => $pol,
                    'pod' => $pod
                ]);
                
                $results[$carrierCode] = [];
            }
        }
        
        return $results;
    }
    
    public function updateSchedulesInDatabase(array $schedules, string $pol, string $pod): void
    {
        $polPort = Port::where('code', $pol)->first();
        $podPort = Port::where('code', $pod)->first();
        
        if (!$polPort || !$podPort) {
            Log::error('Ports not found for schedule update', [
                'pol' => $pol,
                'pod' => $pod,
                'pol_found' => $polPort ? true : false,
                'pod_found' => $podPort ? true : false
            ]);
            return;
        }
        
        foreach ($schedules as $carrierCode => $carrierSchedules) {
            $carrier = ShippingCarrier::where('code', $carrierCode)->first();
            if (!$carrier) {
                Log::warning('Carrier not found', ['carrier_code' => $carrierCode]);
                continue;
            }
            
            foreach ($carrierSchedules as $scheduleData) {
                $this->updateOrCreateSchedule($carrier, $polPort, $podPort, $scheduleData);
            }
        }
    }
    
    private function updateOrCreateSchedule(
        ShippingCarrier $carrier, 
        Port $polPort, 
        Port $podPort, 
        array $scheduleData
    ): void {
        $schedule = ShippingSchedule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'pol_id' => $polPort->id,
                'pod_id' => $podPort->id,
                'service_name' => $scheduleData['service_name'] ?? null,
            ],
            [
                'frequency_per_week' => $scheduleData['frequency_per_week'] ?? null,
                'frequency_per_month' => $scheduleData['frequency_per_month'] ?? null,
                'transit_days' => $scheduleData['transit_days'] ?? null,
                'vessel_name' => $scheduleData['vessel_name'] ?? null,
                'vessel_class' => $scheduleData['vessel_class'] ?? null,
                'ets_pol' => $scheduleData['ets_pol'] ?? null,
                'eta_pod' => $scheduleData['eta_pod'] ?? null,
                'next_sailing_date' => $scheduleData['next_sailing_date'] ?? null,
                'last_updated' => now(),
                'is_active' => true,
            ]
        );
        
        Log::debug('Schedule updated/created', [
            'carrier' => $carrier->code,
            'pol' => $polPort->code,
            'pod' => $podPort->code,
            'service' => $scheduleData['service_name'] ?? 'N/A',
            'schedule_id' => $schedule->id
        ]);
    }
}


