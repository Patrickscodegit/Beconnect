<?php

namespace App\Services\ScheduleExtraction;

use App\Models\ShippingCarrier;
use App\Models\Port;
use App\Models\ShippingSchedule;
use App\Services\AI\AIScheduleValidationService;
use Illuminate\Support\Facades\Log;

class ScheduleExtractionPipeline
{
    private array $strategies = [];
    private AIScheduleValidationService $aiValidator;
    
    public function __construct(AIScheduleValidationService $aiValidator)
    {
        $this->aiValidator = $aiValidator;
    }
    
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
                    
                    // If schedules are nested by carrier code, flatten them
                    if (is_array($schedules) && !empty($schedules)) {
                        $firstSchedule = reset($schedules);
                        if (is_array($firstSchedule) && !isset($firstSchedule['service_name'])) {
                            // This is nested by carrier code, flatten it
                            foreach ($schedules as $nestedCarrierCode => $nestedSchedules) {
                                $results[$nestedCarrierCode] = [$nestedSchedules];
                            }
                        } else {
                            // This is a flat array of schedules
                            $results[$carrierCode] = $schedules;
                        }
                    } else {
                        $results[$carrierCode] = $schedules;
                    }
                    
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
            
            // Apply AI validation to schedules before saving
            $validatedSchedules = $this->aiValidator->validateSchedules($carrierSchedules, "{$pol}->{$pod}");
            
            foreach ($validatedSchedules as $scheduleData) {
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
        // New unique constraint includes ETS (sailing date) to allow multiple voyages
        // Same vessel can now have multiple schedules for same route with different sailing dates
        $schedule = ShippingSchedule::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'pol_id' => $polPort->id,
                'pod_id' => $podPort->id,
                'service_name' => $scheduleData['service_name'] ?? null,
                'vessel_name' => $scheduleData['vessel_name'] ?? null,
                'ets_pol' => $scheduleData['ets_pol'] ?? null, // Added to unique key for multiple voyages
            ],
            [
                'frequency_per_week' => $scheduleData['frequency_per_week'] ?? null,
                'frequency_per_month' => $scheduleData['frequency_per_month'] ?? null,
                'transit_days' => $scheduleData['transit_days'] ?? null,
                'voyage_number' => $scheduleData['voyage_number'] ?? null,
                'vessel_class' => $scheduleData['vessel_class'] ?? null,
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
            'vessel' => $scheduleData['vessel_name'] ?? 'N/A',
            'voyage' => $scheduleData['voyage_number'] ?? 'N/A',
            'schedule_id' => $schedule->id
        ]);
    }
}


