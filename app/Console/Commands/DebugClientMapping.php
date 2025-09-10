<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Console\Command;

class DebugClientMapping extends Command
{
    protected $signature = 'debug:client-mapping {--intake=} {--company=}';
    protected $description = 'Inspect and verify customer mapping (extraction → normalized → Robaws payload → template placeholders).';

    public function handle(): int
    {
        $intakeId = $this->option('intake');
        $wantCompany = $this->option('company');

        if (!$intakeId) {
            $this->error('Pass --intake=<ID>');
            return self::FAILURE;
        }

        $intake = Intake::with('documents.extraction')->find($intakeId);
        if (!$intake) {
            $this->error("Intake {$intakeId} not found.");
            return self::FAILURE;
        }

        $this->info("=== DEBUGGING CLIENT MAPPING FOR INTAKE {$intakeId} ===");
        if ($wantCompany) {
            $this->info("Preferred company: {$wantCompany}");
        }
        $this->newLine();

        // 1) Load extraction payload (however you store it)
        $extraction = $intake->extraction_data ?? [];
        
        // If no extraction data on intake, try from documents
        if (empty($extraction)) {
            $doc = $intake->documents()->with('extraction')->latest()->first();
            if ($doc && $doc->extraction) {
                $extraction = $doc->extraction->extracted_data ?? [];
                if (is_string($extraction)) {
                    $extraction = json_decode($extraction, true) ?? [];
                }
            }
        }

        // If still no data, try to extract from latest document now
        if (empty($extraction)) {
            $doc = Document::where('intake_id', $intake->id)->latest()->first();
            if ($doc) {
                try {
                    $extraction = app(\App\Services\ExtractionService::class)
                        ->extractFromDocument($doc);
                } catch (\Exception $e) {
                    $this->warn("Failed to extract from document: " . $e->getMessage());
                }
            }
        }

        if (empty($extraction)) {
            $this->warn('No extraction payload found.');
            return self::SUCCESS;
        }

        // 2) Normalize to canonical customer array
        $normalized = app(\App\Support\CustomerNormalizer::class)->normalize($extraction, [
            'preferred_company' => $wantCompany, // e.g. "Armos BV"
            'default_country'   => 'BE',
        ]);

        // 3) Build Robaws client payload
        $clientPayload = app(\App\Services\Export\Clients\RobawsApiClient::class)
            ->buildRobawsClientPayload($normalized);

        // 4) Build template placeholders (for your ${client}, ${clientAddress}, etc.)
        $placeholders = app(\App\Services\Export\Mappers\RobawsMapper::class)
            ->buildClientDisplayPlaceholders($normalized);

        // Pretty print
        $this->info('--- EXTRACTED (raw) ---');
        $this->line(json_encode($extraction['fields'] ?? $extraction, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        $this->info('--- NORMALIZED (customer) ---');
        $this->line(json_encode($normalized, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        $this->info('--- ROBAWS CLIENT PAYLOAD ---');
        $this->line(json_encode($clientPayload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        $this->info('--- TEMPLATE PLACEHOLDERS (extraFields) ---');
        $this->line(json_encode($placeholders, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        // Quick sanity
        $hasName = !empty($clientPayload['name']);
        $hasContact = !empty($clientPayload['contacts'][0]['email'] ?? null) || 
                     !empty($clientPayload['contacts'][0]['phone'] ?? null);
        $ok = $hasName && $hasContact;
        
        $this->newLine();
        $this->info($ok ? '✅ Mapping looks OK' : '⚠️ Missing key fields');
        
        if (!$hasName) {
            $this->warn('  - Missing client name');
        }
        if (!$hasContact) {
            $this->warn('  - Missing contact email or phone');
        }

        return self::SUCCESS;
    }
}
