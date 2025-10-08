<?php

namespace App\Services\ScheduleExtraction;

use App\Services\AI\OpenAIService;
use Illuminate\Support\Facades\Log;

class AIScheduleExtractionStrategy implements ScheduleExtractionStrategyInterface
{
    private OpenAIService $openaiService;

    public function __construct(OpenAIService $openaiService)
    {
        $this->openaiService = $openaiService;
    }

    /**
     * Extract schedules using AI parsing
     */
    public function extractSchedules(string $pol, string $pod): array
    {
        Log::info('Starting AI schedule extraction', [
            'pol' => $pol,
            'pod' => $pod
        ]);

        try {
            // Fetch the HTML content from Sallaum website
            $html = $this->fetchSallaumHTML($pol, $pod);
            
            if (empty($html)) {
                Log::warning('No HTML content fetched for AI parsing', [
                    'pol' => $pol,
                    'pod' => $pod
                ]);
                return [];
            }

            // Use AI to parse the HTML table
            $aiSchedules = $this->openaiService->parseHTMLTable($html, $pol, $pod);

            // Convert AI response to standard format
            $schedules = $this->convertToStandardFormat($aiSchedules, $pol, $pod);

            Log::info('AI schedule extraction completed', [
                'pol' => $pol,
                'pod' => $pod,
                'schedules_found' => count($schedules)
            ]);

            return $schedules;

        } catch (\Exception $e) {
            Log::error('AI schedule extraction failed', [
                'pol' => $pol,
                'pod' => $pod,
                'error' => $e->getMessage()
            ]);

            // Return empty array on failure (fallback to traditional parsing)
            return [];
        }
    }

    /**
     * Fetch HTML content from Sallaum website
     */
    private function fetchSallaumHTML(string $pol, string $pod): string
    {
        $url = 'https://sallaumlines.com/schedules/europe-to-west-and-south-africa/';
        
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)->get($url);
            
            if ($response->successful()) {
                return $response->body();
            }

            Log::error('Failed to fetch Sallaum HTML', [
                'url' => $url,
                'status' => $response->status()
            ]);

        } catch (\Exception $e) {
            Log::error('Exception while fetching Sallaum HTML', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }

        return '';
    }

    /**
     * Convert AI response to standard schedule format
     */
    private function convertToStandardFormat(array $aiSchedules, string $pol, string $pod): array
    {
        $schedules = [];

        foreach ($aiSchedules as $aiSchedule) {
            $schedule = [
                'pol_code' => $pol,
                'pod_code' => $pod,
                'carrier_code' => 'SALLAUM',
                'carrier_name' => 'Sallaum Lines',
                'service_type' => 'RORO',
                'service_name' => "Europe to Africa",
                'vessel_name' => $aiSchedule['vessel_name'] ?? '',
                'voyage_number' => $aiSchedule['voyage_number'] ?? '',
                'ets_pol' => $aiSchedule['ets_pol'] ?? null,
                'eta_pod' => $aiSchedule['eta_pod'] ?? null,
                'frequency_per_week' => 1.0,
                'frequency_per_month' => 4.0,
                'transit_days' => $aiSchedule['transit_days'] ?? null,
                'next_sailing_date' => $aiSchedule['ets_pol'] ?? null,
                'data_source' => 'ai_parsing',
                'source_url' => 'https://sallaumlines.com/schedules/europe-to-west-and-south-africa/'
            ];

            // Validate the schedule data
            if ($this->validateSchedule($schedule)) {
                $schedules[] = $schedule;
            } else {
                Log::warning('AI generated invalid schedule, skipping', [
                    'schedule' => $schedule
                ]);
            }
        }

        return $schedules;
    }

    /**
     * Validate AI-generated schedule
     */
    private function validateSchedule(array $schedule): bool
    {
        // Check required fields
        if (empty($schedule['vessel_name']) || empty($schedule['ets_pol']) || empty($schedule['eta_pod'])) {
            return false;
        }

        // Check date format
        $etsDate = \DateTime::createFromFormat('Y-m-d', $schedule['ets_pol']);
        $etaDate = \DateTime::createFromFormat('Y-m-d', $schedule['eta_pod']);
        
        if (!$etsDate || !$etaDate) {
            return false;
        }

        // Check that ETA is after ETS
        if ($etaDate <= $etsDate) {
            return false;
        }

        // Check transit days are reasonable
        $transitDays = $schedule['transit_days'] ?? 0;
        if ($transitDays < 1 || $transitDays > 60) {
            return false;
        }

        return true;
    }

    /**
     * Get carrier code
     */
    public function getCarrierCode(): string
    {
        return 'SALLAUM';
    }

    /**
     * Get update frequency
     */
    public function getUpdateFrequency(): string
    {
        return 'daily';
    }

    /**
     * Get last update time
     */
    public function getLastUpdate(): ?\DateTime
    {
        return new \DateTime();
    }

    /**
     * Check if this strategy supports the given route
     */
    public function supports(string $polCode, string $podCode): bool
    {
        // Only support the 3 required POLs: Antwerp, Zeebrugge, Flushing
        $supportedPols = ['ANR', 'ZEE', 'FLU'];
        
        if (!in_array($polCode, $supportedPols)) {
            return false;
        }

        // Support all Sallaum PODs
        $supportedPods = [
            'DKR', 'CKY', 'ABJ', 'LFW', 'COO', 'LOS', 'DLA', 
            'PNR', 'WVB', 'PLZ', 'ELS'
        ];

        return in_array($podCode, $supportedPods);
    }
}


