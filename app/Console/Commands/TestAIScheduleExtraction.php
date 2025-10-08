<?php

namespace App\Console\Commands;

use App\Services\AI\OpenAIService;
use App\Services\AI\AIScheduleValidationService;
use App\Services\ScheduleExtraction\AIScheduleExtractionStrategy;
use Illuminate\Console\Command;

class TestAIScheduleExtraction extends Command
{
    protected $signature = 'ai:test-schedule-extraction {pol} {pod} {--enable-ai}';
    protected $description = 'Test AI-powered schedule extraction for a specific route';

    public function handle(): void
    {
        $pol = $this->argument('pol');
        $pod = $this->argument('pod');
        $enableAI = $this->option('enable-ai');

        $this->info("Testing AI schedule extraction for {$pol} -> {$pod}");

        // Initialize services
        $openaiService = new OpenAIService();
        $aiValidator = new AIScheduleValidationService($openaiService);
        $aiStrategy = new AIScheduleExtractionStrategy($openaiService);

        // Test AI validation
        $this->info("\n=== AI Validation Test ===");
        $sampleSchedules = [
            [
                'vessel_name' => 'Test Vessel 1',
                'voyage_number' => 'TEST001',
                'ets_pol' => '2025-10-07',
                'eta_pod' => '2025-10-17',
                'transit_days' => 10
            ],
            [
                'vessel_name' => 'Test Vessel 2',
                'voyage_number' => 'TEST002',
                'ets_pol' => '2025-10-14',
                'eta_pod' => '2025-10-24',
                'transit_days' => 10
            ]
        ];

        $shouldUseAI = $aiValidator->shouldUseAI($sampleSchedules);
        $this->line("Should use AI validation: " . ($shouldUseAI ? 'YES' : 'NO'));

        if ($shouldUseAI) {
            $this->info("Validating schedules with AI...");
            $validatedSchedules = $aiValidator->validateSchedules($sampleSchedules, "{$pol}->{$pod}");
            $this->line("Validated schedules count: " . count($validatedSchedules));
        }

        // Test AI parsing
        if ($enableAI) {
            $this->info("\n=== AI Parsing Test ===");
            $this->info("Extracting schedules with AI...");
            
            try {
                $schedules = $aiStrategy->extractSchedules($pol, $pod);
                $this->line("AI extracted schedules count: " . count($schedules));
                
                foreach ($schedules as $i => $schedule) {
                    $this->line("Schedule " . ($i + 1) . ":");
                    $this->line("  Vessel: " . $schedule['vessel_name']);
                    $this->line("  Voyage: " . $schedule['voyage_number']);
                    $this->line("  ETS: " . $schedule['ets_pol']);
                    $this->line("  ETA: " . $schedule['eta_pod']);
                    $this->line("  Transit: " . $schedule['transit_days'] . " days");
                }
            } catch (\Exception $e) {
                $this->error("AI parsing failed: " . $e->getMessage());
            }
        } else {
            $this->info("\n=== AI Parsing Test (Skipped) ===");
            $this->line("Use --enable-ai flag to test AI parsing");
        }

        // Test strategy support
        $this->info("\n=== Strategy Support Test ===");
        $supports = $aiStrategy->supports($pol, $pod);
        $this->line("Strategy supports {$pol}->{$pod}: " . ($supports ? 'YES' : 'NO'));
        
        $carrierCode = $aiStrategy->getCarrierCode();
        $this->line("Carrier code: {$carrierCode}");
        
        $updateFreq = $aiStrategy->getUpdateFrequency();
        $this->line("Update frequency: {$updateFreq}");

        // Configuration summary
        $this->info("\n=== Configuration Summary ===");
        $this->line("AI Parsing Enabled: " . (config('schedule_extraction.use_ai_parsing') ? 'YES' : 'NO'));
        $this->line("AI Validation Enabled: " . (config('schedule_extraction.use_ai_validation') ? 'YES' : 'NO'));
        $this->line("OpenAI API Key: " . (empty(config('schedule_extraction.openai_api_key')) ? 'NOT CONFIGURED' : 'CONFIGURED'));
        $this->line("AI Validation Threshold: " . config('schedule_extraction.ai_validation_threshold'));
        $this->line("AI Fallback Enabled: " . (config('schedule_extraction.ai_fallback_enabled') ? 'YES' : 'NO'));

        $this->info("\n✅ AI schedule extraction test completed!");
    }
}




