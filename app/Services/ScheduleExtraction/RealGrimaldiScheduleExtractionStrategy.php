<?php

namespace App\Services\ScheduleExtraction;

use Illuminate\Support\Facades\Log;
use App\Services\Grimaldi\GrimaldiApiClient;

class RealGrimaldiScheduleExtractionStrategy extends RealDataExtractionStrategy
{
    private GrimaldiApiClient $apiClient;

    public function __construct()
    {
        parent::__construct('GRIMALDI', 'Grimaldi Lines', 'https://www.gnet.grimaldi-eservice.com');
        $this->apiClient = new GrimaldiApiClient();
    }

    protected function fetchRealSchedules(string $polCode, string $podCode): array
    {
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'RealGrimaldiScheduleExtractionStrategy.php:fetchRealSchedules',
            'message' => 'Starting schedule fetch',
            'data' => ['pol' => $polCode, 'pod' => $podCode],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'D'
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        try {
            // Map port codes to Grimaldi format (e.g., ANR -> BEANR)
            $grimaldiPol = $this->mapPortCodeToGrimaldi($polCode);
            $grimaldiPod = $this->mapPortCodeToGrimaldi($podCode);

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'RealGrimaldiScheduleExtractionStrategy.php:fetchRealSchedules',
                'message' => 'Port code mapping result',
                'data' => ['pol' => $polCode, 'grimaldi_pol' => $grimaldiPol, 'pod' => $podCode, 'grimaldi_pod' => $grimaldiPod],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D'
            ]) . "\n", FILE_APPEND);
            // #endregion

            if (!$grimaldiPol || !$grimaldiPod) {
                Log::warning("Grimaldi: Could not map port codes", [
                    'pol' => $polCode,
                    'pod' => $podCode,
                ]);
                return [];
            }

            Log::info("Grimaldi: Fetching schedules via API", [
                'pol' => $polCode,
                'pod' => $podCode,
                'grimaldi_pol' => $grimaldiPol,
                'grimaldi_pod' => $grimaldiPod,
            ]);

            // Fetch schedules from API (60 days lookahead)
            $apiResponse = $this->apiClient->getSailingSchedule($grimaldiPol, $grimaldiPod, 60);

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'RealGrimaldiScheduleExtractionStrategy.php:fetchRealSchedules',
                'message' => 'API response received',
                'data' => ['pol' => $polCode, 'pod' => $podCode, 'response_type' => gettype($apiResponse), 'is_array' => is_array($apiResponse), 'is_null' => is_null($apiResponse), 'count' => is_array($apiResponse) ? count($apiResponse) : 0, 'response_sample' => is_array($apiResponse) && !empty($apiResponse) ? array_slice($apiResponse, 0, 1) : null],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B'
            ]) . "\n", FILE_APPEND);
            // #endregion

            if (empty($apiResponse)) {
                Log::info("Grimaldi: No schedules found via API", [
                    'pol' => $polCode,
                    'pod' => $podCode,
                ]);
                return [];
            }

            // Parse API response to standard format
            $parsed = $this->parseGrimaldiApiResponse($apiResponse, $polCode, $podCode);
            
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'RealGrimaldiScheduleExtractionStrategy.php:fetchRealSchedules',
                'message' => 'Parsed schedules result',
                'data' => ['pol' => $polCode, 'pod' => $podCode, 'parsed_count' => count($parsed), 'schedules' => $parsed],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'E'
            ]) . "\n", FILE_APPEND);
            // #endregion
            
            return $parsed;

        } catch (\Exception $e) {
            Log::error("Grimaldi: Failed to fetch schedules via API", [
                'pol' => $polCode,
                'pod' => $podCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return [];
    }

    /**
     * Map internal port codes to Grimaldi API format
     * Grimaldi uses specific codes like BEANR (Belgium Antwerp), NGLOS (Nigeria Lagos)
     */
    protected function mapPortCodeToGrimaldi(string $portCode): ?string
    {
        // Port code mapping: internal code -> Grimaldi API code
        $mapping = [
            // POLs (Ports of Loading)
            'ANR' => 'BEANR', // Antwerp, Belgium
            'ZEE' => 'BEZEE', // Zeebrugge, Belgium (if supported)
            'FLU' => 'NLFLU', // Flushing, Netherlands (if supported)
            
            // PODs (Ports of Discharge) - West Africa
            'DKR' => 'SNDKR', // Dakar, Senegal
            'CKY' => 'GNCKY', // Conakry, Guinea
            'ABJ' => 'CIABJ', // Abidjan, Côte d'Ivoire
            'LFW' => 'TGLFW', // Lomé, Togo
            'COO' => 'BJCOO', // Cotonou, Benin
            'LOS' => 'NGLOS', // Lagos, Nigeria
            'DLA' => 'CMDLA', // Douala, Cameroon
            'PNR' => 'CGPNR', // Pointe Noire, Congo
            
            // PODs - East/South Africa
            'DAR' => 'TZDAR', // Dar es Salaam, Tanzania
            'MBA' => 'KEMBA', // Mombasa, Kenya
            'DUR' => 'ZADUR', // Durban, South Africa
            'ELS' => 'ZAELS', // East London, South Africa
            'PLZ' => 'ZAPLZ', // Port Elizabeth, South Africa
            'WVB' => 'NAWVB', // Walvis Bay, Namibia
        ];

        // If exact match found, return it
        if (isset($mapping[$portCode])) {
            return $mapping[$portCode];
        }

        // If port code already looks like Grimaldi format (5 chars with country prefix), return as-is
        if (strlen($portCode) >= 5 && preg_match('/^[A-Z]{2}[A-Z]{3}$/', $portCode)) {
            return strtoupper($portCode);
        }

        // Try to find by partial match (last 3 chars)
        $lastThree = strtoupper(substr($portCode, -3));
        foreach ($mapping as $internal => $grimaldi) {
            if (strtoupper(substr($grimaldi, -3)) === $lastThree) {
                Log::info("Grimaldi: Port code partial match", [
                    'internal' => $portCode,
                    'mapped' => $grimaldi,
                ]);
                return $grimaldi;
            }
        }

        // If no mapping found, try using the port code as-is (might already be in Grimaldi format)
        Log::warning("Grimaldi: No port code mapping found, using as-is", [
            'port_code' => $portCode,
        ]);
        return strtoupper($portCode);
    }

    /**
     * Parse Grimaldi API response and convert to standard schedule format
     */
    protected function parseGrimaldiApiResponse($apiResponse, string $polCode, string $podCode): array
    {
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'RealGrimaldiScheduleExtractionStrategy.php:parseGrimaldiApiResponse',
            'message' => 'Starting API response parsing',
            'data' => ['pol' => $polCode, 'pod' => $podCode, 'response_type' => gettype($apiResponse), 'response_keys' => is_array($apiResponse) ? array_keys($apiResponse) : null],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'E'
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        $schedules = [];

        // Handle different response formats
        // API might return array of schedules or single schedule object
        $scheduleData = is_array($apiResponse) ? $apiResponse : [$apiResponse];

        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'RealGrimaldiScheduleExtractionStrategy.php:parseGrimaldiApiResponse',
            'message' => 'Schedule data prepared',
            'data' => ['pol' => $polCode, 'pod' => $podCode, 'schedule_data_count' => count($scheduleData), 'first_item_keys' => is_array($scheduleData[0] ?? null) ? array_keys($scheduleData[0]) : null],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'E'
        ]) . "\n", FILE_APPEND);
        // #endregion

        foreach ($scheduleData as $index => $scheduleItem) {
            if (!is_array($scheduleItem)) {
                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'RealGrimaldiScheduleExtractionStrategy.php:parseGrimaldiApiResponse',
                    'message' => 'Skipping non-array schedule item',
                    'data' => ['index' => $index, 'item_type' => gettype($scheduleItem)],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'E'
                ]) . "\n", FILE_APPEND);
                // #endregion
                continue;
            }

            try {
                $schedule = $this->convertApiScheduleToStandardFormat($scheduleItem, $polCode, $podCode);
                
                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'RealGrimaldiScheduleExtractionStrategy.php:parseGrimaldiApiResponse',
                    'message' => 'Schedule converted',
                    'data' => ['index' => $index, 'schedule' => $schedule, 'is_valid' => $schedule ? $this->validateSchedule($schedule) : false],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'E'
                ]) . "\n", FILE_APPEND);
                // #endregion
                
                if ($schedule && $this->validateSchedule($schedule)) {
                    $schedules[] = $schedule;
                }
            } catch (\Exception $e) {
                Log::warning("Grimaldi: Failed to parse schedule item", [
                    'error' => $e->getMessage(),
                    'item' => $scheduleItem,
                ]);
                
                // #region agent log
                @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                    'timestamp' => time() * 1000,
                    'location' => 'RealGrimaldiScheduleExtractionStrategy.php:parseGrimaldiApiResponse',
                    'message' => 'Parse exception',
                    'data' => ['index' => $index, 'error' => $e->getMessage()],
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'E'
                ]) . "\n", FILE_APPEND);
                // #endregion
            }
        }

        Log::info("Grimaldi: Parsed schedules from API", [
            'pol' => $polCode,
            'pod' => $podCode,
            'schedules_found' => count($schedules),
        ]);

        return $schedules;
    }

    /**
     * Convert a single API schedule item to standard format
     */
    protected function convertApiScheduleToStandardFormat(array $apiSchedule, string $polCode, string $podCode): ?array
    {
        // Map API fields to standard format based on PDF data dictionary
        // PDF shows: vessel_name, imo_no, pol_serv, pol, pol_call_id, pol_vyg, pol_ets, pot, pot_eta, pod, pod_vyg, pod_eta
        // Field names may vary - handle multiple possible field names
        $vesselName = $apiSchedule['vessel_name'] ?? $apiSchedule['VesselName'] ?? $apiSchedule['Vessel'] ?? null;
        $voyageNumber = $apiSchedule['pol_vyg'] ?? $apiSchedule['pod_vyg'] ?? $apiSchedule['VoyageNo'] ?? $apiSchedule['Voyage'] ?? $apiSchedule['voyage_number'] ?? null;
        $etsPol = $apiSchedule['pol_ets'] ?? $apiSchedule['ETSPOL'] ?? $apiSchedule['ETS'] ?? $apiSchedule['DepartureDate'] ?? null;
        $etaPod = $apiSchedule['pod_eta'] ?? $apiSchedule['ETAPOD'] ?? $apiSchedule['ETA'] ?? $apiSchedule['ArrivalDate'] ?? null;
        $polService = $apiSchedule['pol_serv'] ?? $apiSchedule['ServiceName'] ?? $apiSchedule['Service'] ?? null;
        $transitDays = $apiSchedule['TransitDays'] ?? $apiSchedule['Transit'] ?? $apiSchedule['transit_days'] ?? null;
        $serviceName = $apiSchedule['ServiceName'] ?? $apiSchedule['Service'] ?? $apiSchedule['service_name'] ?? null;
        $vesselClass = $apiSchedule['VesselClass'] ?? $apiSchedule['vessel_class'] ?? null;

        // Parse dates
        $etsPolDate = $this->parseDate($etsPol);
        $etaPodDate = $this->parseDate($etaPod);

        // Calculate transit days if not provided
        if (!$transitDays && $etsPolDate && $etaPodDate) {
            $transitDays = $etsPolDate->diff($etaPodDate)->days;
        }

        // Calculate next sailing date (use ETS POL)
        $nextSailingDate = $etsPolDate ? $etsPolDate->format('Y-m-d') : null;

        // Build standard schedule format
        $schedule = [
            'pol_code' => $polCode,
            'pod_code' => $podCode,
            'carrier_code' => 'GRIMALDI',
            'carrier_name' => 'Grimaldi Lines',
            'service_type' => 'RORO', // Grimaldi primarily handles RORO
            'service_name' => $serviceName ?? 'Grimaldi Service',
            'vessel_name' => $vesselName,
            'voyage_number' => $voyageNumber,
            'vessel_class' => $vesselClass,
            'ets_pol' => $etsPolDate ? $etsPolDate->format('Y-m-d') : null,
            'eta_pod' => $etaPodDate ? $etaPodDate->format('Y-m-d') : null,
            'transit_days' => $transitDays,
            'next_sailing_date' => $nextSailingDate,
            'frequency_per_week' => null, // Will be calculated from multiple schedules
            'frequency_per_month' => null, // Will be calculated from multiple schedules
            'data_source' => 'grimaldi_api',
        ];

        return $schedule;
    }

    /**
     * Parse date from various formats
     */
    protected function parseDate($dateValue): ?\DateTime
    {
        if (empty($dateValue)) {
            return null;
        }

        // If already a DateTime object
        if ($dateValue instanceof \DateTime) {
            return $dateValue;
        }

        // Try common date formats
        $formats = [
            'Y-m-d\TH:i:s',      // ISO 8601 with time
            'Y-m-d\TH:i:s.u',    // ISO 8601 with microseconds
            'Y-m-d H:i:s',       // Standard datetime
            'Y-m-d',             // Date only
            'd/m/Y H:i:s',       // European format with time
            'd/m/Y',             // European format
            'd-m-Y H:i:s',       // European format with dashes
            'd-m-Y',             // European format with dashes
        ];

        foreach ($formats as $format) {
            try {
                $date = \DateTime::createFromFormat($format, $dateValue);
                if ($date !== false) {
                    return $date;
                }
            } catch (\Exception $e) {
                // Continue to next format
            }
        }

        // Try strtotime as fallback
        try {
            $timestamp = strtotime($dateValue);
            if ($timestamp !== false) {
                return new \DateTime('@' . $timestamp);
            }
        } catch (\Exception $e) {
            Log::warning("Grimaldi: Could not parse date", [
                'value' => $dateValue,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Validate schedule data
     */
    protected function validateSchedule(array $schedule): bool
    {
        // Must have at least vessel name and dates
        if (empty($schedule['vessel_name']) || empty($schedule['ets_pol']) || empty($schedule['eta_pod'])) {
            return false;
        }

        // Validate date format
        $etsDate = \DateTime::createFromFormat('Y-m-d', $schedule['ets_pol']);
        $etaDate = \DateTime::createFromFormat('Y-m-d', $schedule['eta_pod']);

        if (!$etsDate || !$etaDate) {
            return false;
        }

        // ETA must be after ETS
        if ($etaDate <= $etsDate) {
            return false;
        }

        // Transit days should be reasonable (1-60 days)
        if (isset($schedule['transit_days']) && ($schedule['transit_days'] < 1 || $schedule['transit_days'] > 60)) {
            return false;
        }

        return true;
    }

    protected function parseRealSchedules(array $realData, string $polCode, string $podCode): array
    {
        // This method is called by parent class, but we handle parsing in fetchRealSchedules
        return $realData;
    }

    public function supports(string $polCode, string $podCode): bool
    {
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'RealGrimaldiScheduleExtractionStrategy.php:supports',
            'message' => 'Checking if route is supported',
            'data' => ['pol' => $polCode, 'pod' => $podCode],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C'
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        // Focus on Antwerp (BEANR) as POL for now
        // Support all PODs that can be mapped to Grimaldi format
        $supportedPols = ['ANR']; // Only Antwerp for now
        
        if (!in_array($polCode, $supportedPols)) {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'timestamp' => time() * 1000,
                'location' => 'RealGrimaldiScheduleExtractionStrategy.php:supports',
                'message' => 'POL not supported',
                'data' => ['pol' => $polCode, 'supported_pols' => $supportedPols],
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C'
            ]) . "\n", FILE_APPEND);
            // #endregion
            return false;
        }

        // Check if POD can be mapped to Grimaldi format
        $grimaldiPod = $this->mapPortCodeToGrimaldi($podCode);
        
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'timestamp' => time() * 1000,
            'location' => 'RealGrimaldiScheduleExtractionStrategy.php:supports',
            'message' => 'POD mapping result',
            'data' => ['pod' => $podCode, 'grimaldi_pod' => $grimaldiPod, 'supported' => !empty($grimaldiPod)],
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C'
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        return !empty($grimaldiPod);
    }
}


