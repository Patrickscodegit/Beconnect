<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Console\Command;

class DebugRobawsPayload extends Command
{
    protected $signature = 'robaws:debug-payload {intake_id}';
    protected $description = 'Debug the Robaws payload for an intake';

    public function handle()
    {
        $intakeId = $this->argument('intake_id');
        $intake = Intake::find($intakeId);
        
        if (!$intake) {
            $this->error("Intake with ID {$intakeId} not found.");
            return 1;
        }

        $service = app(RobawsExportService::class);
        $audit = $service->getExportAudit($intake);
        
        $this->info("=== INTAKE DATA ===");
        $this->line(json_encode($audit['intake'], JSON_PRETTY_PRINT));
        
        $this->info("\n=== EXTRACTION DATA ===");
        $this->line(json_encode($audit['extraction_summary'], JSON_PRETTY_PRINT));
        
        $this->info("\n=== PAYLOAD SUMMARY ===");
        $this->line(json_encode($audit['payload_summary'], JSON_PRETTY_PRINT));
        
        $this->info("\n=== MAPPING COMPLETENESS ===");
        $this->line(json_encode($audit['mapping_completeness'], JSON_PRETTY_PRINT));
        
        // Get the actual payload
        $extractionData = $this->getExtractionData($intake);
        $mapper = app(\App\Services\Export\Mappers\RobawsMapper::class);
        $mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);
        $flatPayload = $mapper->toRobawsPayloadFlat($mapped);
        
        $this->info("\n=== ACTUAL NESTED PAYLOAD ===");
        $this->line(json_encode($mapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->info("\n=== FLATTENED ROBAWS PAYLOAD ===");
        $this->line(json_encode($flatPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->info("\n=== PAYLOAD COMPARISON ===");
        $this->line("Nested payload size: " . strlen(json_encode($mapped)));
        $this->line("Flat payload size: " . strlen(json_encode($flatPayload)));
        $this->line("Flat payload fields: " . count($flatPayload));
        $this->line("Sample flat fields: " . implode(', ', array_slice(array_keys($flatPayload), 0, 10)));
        
        return 0;
    }
    
    private function getExtractionData(Intake $intake): array
    {
        $base = $intake->extraction?->data ?? [];

        foreach ($intake->documents as $doc) {
            // Prefer singular extraction (latest) but fall back to last of extractions
            $docExtraction = $doc->extraction ?? $doc->extractions->last();
            $docData = $docExtraction?->data ?? $docExtraction?->extracted_data ?? [];
            $base = array_replace_recursive($base, $docData);
        }

        return $base;
    }
}
