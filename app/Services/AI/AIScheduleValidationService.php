<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

class AIScheduleValidationService
{
    private OpenAIService $openaiService;

    public function __construct(OpenAIService $openaiService)
    {
        $this->openaiService = $openaiService;
    }

    /**
     * Validate schedules using AI
     */
    public function validateSchedules(array $schedules, string $route): array
    {
        // Skip AI validation if not configured or schedules are empty
        if (!$this->shouldUseAI($schedules)) {
            return $schedules;
        }

        Log::info('Starting AI validation', [
            'route' => $route,
            'schedules_count' => count($schedules)
        ]);

        return $this->openaiService->validateSchedules($schedules, $route);
    }

    /**
     * Determine if AI validation should be used
     */
    public function shouldUseAI(array $schedules): bool
    {
        // TEMPORARILY DISABLED: AI validation is filtering out valid long-distance routes
        // Re-enable after adjusting transit time expectations
        Log::info('AI validation temporarily disabled to allow all valid schedules');
        return false;
        
        // Check if AI is enabled
        if (!config('schedule_extraction.use_ai_validation', false)) {
            return false;
        }

        // Check if we have OpenAI API key
        if (empty(config('schedule_extraction.openai_api_key'))) {
            return false;
        }

        // Use AI validation when:
        // 1. Too many schedules (might indicate parsing errors)
        if (count($schedules) > 10) {
            Log::info('Using AI validation: too many schedules', ['count' => count($schedules)]);
            return true;
        }

        // 2. Too few schedules (might indicate missing data)
        if (count($schedules) < 2 && !empty($schedules)) {
            Log::info('Using AI validation: too few schedules', ['count' => count($schedules)]);
            return true;
        }

        // 3. Unusual transit times
        foreach ($schedules as $schedule) {
            $transitDays = $schedule['transit_days'] ?? 0;
            if ($transitDays < 1 || $transitDays > 60) {
                Log::info('Using AI validation: unusual transit time', [
                    'vessel' => $schedule['vessel_name'],
                    'transit_days' => $transitDays
                ]);
                return true;
            }
        }

        // 4. Suspicious vessel-route combinations (basic checks)
        $suspiciousCombinations = $this->detectSuspiciousCombinations($schedules);
        if (!empty($suspiciousCombinations)) {
            Log::info('Using AI validation: suspicious combinations detected', [
                'combinations' => $suspiciousCombinations
            ]);
            return true;
        }

        // 5. Enhanced AI validation with vessel-specific knowledge and confidence scoring
        Log::info('Using enhanced AI validation: vessel-specific knowledge + confidence scoring enabled');
        return true;
    }

    /**
     * Detect suspicious vessel-route combinations
     */
    private function detectSuspiciousCombinations(array $schedules): array
    {
        $suspicious = [];

        foreach ($schedules as $schedule) {
            $vesselName = $schedule['vessel_name'] ?? '';
            $transitDays = $schedule['transit_days'] ?? 0;

            // Check for unrealistic transit times for specific routes
            if ($transitDays > 45) {
                $suspicious[] = [
                    'vessel' => $vesselName,
                    'reason' => 'Very long transit time',
                    'transit_days' => $transitDays
                ];
            }

            // Check for very short transit times
            if ($transitDays < 3) {
                $suspicious[] = [
                    'vessel' => $vesselName,
                    'reason' => 'Very short transit time',
                    'transit_days' => $transitDays
                ];
            }

            // Check for duplicate vessel names with different dates
            $duplicates = array_filter($schedules, function($s) use ($vesselName) {
                return ($s['vessel_name'] ?? '') === $vesselName;
            });

            if (count($duplicates) > 1) {
                $suspicious[] = [
                    'vessel' => $vesselName,
                    'reason' => 'Duplicate vessel with different dates',
                    'count' => count($duplicates)
                ];
            }
        }

        return $suspicious;
    }

    /**
     * Get validation statistics
     */
    public function getValidationStats(): array
    {
        return [
            'ai_enabled' => config('schedule_extraction.use_ai_validation', false),
            'api_key_configured' => !empty(config('schedule_extraction.openai_api_key')),
            'validation_threshold' => config('schedule_extraction.ai_validation_threshold', 0.7),
            'fallback_enabled' => config('schedule_extraction.ai_fallback_enabled', true)
        ];
    }
}


