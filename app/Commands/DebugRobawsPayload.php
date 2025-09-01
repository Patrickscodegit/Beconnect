<?php

namespace App\Commands;

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
        $payload = $mapper->mapIntakeToRobaws($intake, $extractionData);
        
        $this->info("\n=== ACTUAL PAYLOAD ===");
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return 0;
    }
    
    private function getExtractionData(Intake $intake): array
    {
        $base = $intake->extraction?->data ?? [];

        foreach ($intake->documents as $doc) {
            $docData = $doc->extraction?->data ?? [];
            $base = array_replace_recursive($base, $docData);
        }

        return $base;
    }
}
