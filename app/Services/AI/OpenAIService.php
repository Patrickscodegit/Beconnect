<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('schedule_extraction.openai_api_key');
    }

    /**
     * Validate shipping schedules using OpenAI
     */
    public function validateSchedules(array $schedules, string $route): array
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured, skipping AI validation');
            return $schedules;
        }

        try {
            $prompt = $this->buildValidationPrompt($schedules, $route);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 1000
            ]);

            if ($response->successful()) {
                $aiResponse = $response->json();
                $validationResult = $this->parseValidationResponse($aiResponse['choices'][0]['message']['content'] ?? '');
                
                Log::info('AI validation completed', [
                    'route' => $route,
                    'schedules_count' => count($schedules),
                    'validation_result' => $validationResult
                ]);

                return $this->applyValidation($schedules, $validationResult);
            }

            Log::error('OpenAI API request failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('OpenAI validation failed', [
                'error' => $e->getMessage(),
                'route' => $route
            ]);
        }

        return $schedules;
    }

    /**
     * Parse HTML table using OpenAI
     */
    public function parseHTMLTable(string $html, string $pol, string $pod): array
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured, skipping AI parsing');
            return [];
        }

        try {
            $prompt = $this->buildParsingPrompt($html, $pol, $pod);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 2000
            ]);

            if ($response->successful()) {
                $aiResponse = $response->json();
                $parsedData = $this->parseAIResponse($aiResponse['choices'][0]['message']['content'] ?? '');
                
                Log::info('AI parsing completed', [
                    'pol' => $pol,
                    'pod' => $pod,
                    'schedules_found' => count($parsedData)
                ]);

                return $parsedData;
            }

            Log::error('OpenAI parsing request failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('OpenAI parsing failed', [
                'error' => $e->getMessage(),
                'pol' => $pol,
                'pod' => $pod
            ]);
        }

        return [];
    }

    /**
     * Build validation prompt for OpenAI
     */
    private function buildValidationPrompt(array $schedules, string $route): string
    {
        $schedulesJson = json_encode($schedules, JSON_PRETTY_PRINT);
        
        return "You are a shipping industry expert. Validate these shipping schedules for route {$route}:

{$schedulesJson}

VALIDATION APPROACH:
- Focus on DATA ACCURACY rather than vessel-route assumptions
- Vessel routes and schedules can change frequently
- Trust the actual schedule data provided
- Validate based on realistic shipping patterns and transit times

        VALIDATION CRITERIA:
        1. **Transit Time Validation**: 
           - Europe to West Africa: 7-30 days (typical range)
           - Europe to South Africa: 20-45 days (Durban, Port Elizabeth, East London, Walvis Bay)
           - Europe to East Africa: 25-50 days (Dar es Salaam, Mombasa)

2. **Date Logic Validation**:
   - ETA must be after ETS (Estimated Time of Sailing)
   - Dates should be realistic (not in the past)
   - Transit times should match distance and typical vessel speeds

3. **Vessel Information Validation**:
   - Vessel names should be realistic shipping vessel names
   - Voyage numbers should follow typical patterns (e.g., 25PA09, 25OB03)
   - Service names should be appropriate for the route

4. **Schedule Consistency**:
   - Multiple schedules for same route should have different dates
   - Frequency information should be realistic (weekly, bi-weekly, monthly)

Please analyze each schedule and return a JSON response in this format:
{
    \"valid_schedules\": [
        {
            \"vessel_name\": \"Vessel Name\",
            \"voyage_number\": \"Voyage123\",
            \"confidence\": 0.95,
            \"issues\": []
        }
    ],
    \"invalid_schedules\": [
        {
            \"vessel_name\": \"Invalid Vessel\",
            \"reason\": \"Unrealistic transit time of 2 days for Europe-Africa route\",
            \"confidence\": 0.9
        }
    ],
    \"overall_assessment\": \"Assessment based on schedule data accuracy\"
}

IMPORTANT: Do NOT assume vessel-route combinations. Focus on validating the schedule data itself.

Return only the JSON response, no additional text.";
    }

    /**
     * Build parsing prompt for OpenAI
     */
    private function buildParsingPrompt(string $html, string $pol, string $pod): string
    {
        return "You are a shipping industry expert. Parse this HTML table from Sallaum Lines shipping schedule:

HTML Content:
{$html}

Extract ALL schedules for route {$pol} -> {$pod} based on the actual table data. Return a JSON array in this format:
[
    {
        \"vessel_name\": \"Piranha\",
        \"voyage_number\": \"25PA09\",
        \"ets_pol\": \"2025-09-02\",
        \"eta_pod\": \"2025-09-12\",
        \"transit_days\": 10
    }
]

EXTRACTION RULES:
1. **Follow the actual schedule data** - don't make assumptions about vessel routes
2. **Extract all vessels** that show dates for both {$pol} and {$pod} ports
3. **Use exact vessel names and voyage numbers** from the table
4. **Format dates as YYYY-MM-DD**
5. **Calculate transit days** correctly (ETA - ETS)
6. **Include all valid schedules** found in the table

IMPORTANT: 
- Vessel routes can change frequently
- Trust the schedule data provided
- Extract what you see, not what you assume
- If a vessel shows dates for both ports, include it

Return only the JSON array, no additional text.";
    }

    /**
     * Parse OpenAI validation response
     */
    private function parseValidationResponse(string $response): array
    {
        try {
            // Extract JSON from response
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                return json_decode($matches[0], true) ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Failed to parse AI validation response', [
                'response' => $response,
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Parse OpenAI parsing response
     */
    private function parseAIResponse(string $response): array
    {
        try {
            // Extract JSON array from response
            if (preg_match('/\[.*\]/s', $response, $matches)) {
                return json_decode($matches[0], true) ?? [];
            }
        } catch (\Exception $e) {
            Log::error('Failed to parse AI parsing response', [
                'response' => $response,
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Apply AI validation to schedules with confidence scoring
     */
    private function applyValidation(array $schedules, array $validationResult): array
    {
        $validSchedules = $validationResult['valid_schedules'] ?? [];
        $invalidSchedules = $validationResult['invalid_schedules'] ?? [];
        
        // Confidence threshold for accepting schedules
        $confidenceThreshold = config('schedule_extraction.ai_validation_threshold', 0.85);

        // Create lookup for invalid schedules
        $invalidVessels = array_column($invalidSchedules, 'vessel_name');
        
        // Create confidence lookup for valid schedules
        $confidenceMap = [];
        foreach ($validSchedules as $validSchedule) {
            $confidenceMap[$validSchedule['vessel_name']] = $validSchedule['confidence'] ?? 0.5;
        }

        // Filter schedules based on validation results and confidence
        $filteredSchedules = array_filter($schedules, function($schedule) use ($invalidVessels, $confidenceMap, $confidenceThreshold) {
            $vesselName = $schedule['vessel_name'];
            
            // Remove if explicitly marked as invalid
            if (in_array($vesselName, $invalidVessels)) {
                return false;
            }
            
            // Check confidence threshold if vessel is in valid list
            if (isset($confidenceMap[$vesselName])) {
                return $confidenceMap[$vesselName] >= $confidenceThreshold;
            }
            
            // If not in validation result, keep with default confidence
            return true;
        });

        Log::info('AI validation applied with confidence scoring', [
            'original_count' => count($schedules),
            'valid_count' => count($filteredSchedules),
            'removed_count' => count($schedules) - count($filteredSchedules),
            'confidence_threshold' => $confidenceThreshold,
            'confidence_scores' => $confidenceMap
        ]);

        return array_values($filteredSchedules);
    }
}


