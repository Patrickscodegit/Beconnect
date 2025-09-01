<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Console\Command;

class RobawsAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'robaws:audit 
                            {intake? : Specific intake ID to audit}
                            {--all : Audit all intakes}
                            {--recent=24 : Audit intakes from last X hours}
                            {--dump : Show detailed payload dumps}
                            {--missing : Only show intakes with missing mappings}
                            {--detailed : Show detailed analysis}';

    /**
     * The console command description.
     */
    protected $description = 'Audit Robaws export mapping for troubleshooting';

    private RobawsExportService $exportService;

    public function __construct(RobawsExportService $exportService)
    {
        parent::__construct();
        $this->exportService = $exportService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Robaws Export Mapping Audit');
        $this->newLine();

        // Determine which intakes to audit
        $intakes = $this->getIntakesToAudit();
        
        if ($intakes->isEmpty()) {
            $this->warn('No intakes found matching criteria.');
            return 0;
        }

        $this->info("Auditing {$intakes->count()} intake(s)...");
        $this->newLine();

        $totalAudited = 0;
        $totalIssues = 0;
        $summaryStats = [];

        foreach ($intakes as $intake) {
            $audit = $this->auditIntake($intake);
            $totalAudited++;
            
            if ($this->hasSignificantIssues($audit)) {
                $totalIssues++;
            }

            $this->collectStats($audit, $summaryStats);

            // Show results if not filtering for missing only, or if there are issues
            if (!$this->option('missing') || $this->hasSignificantIssues($audit)) {
                $this->displayAuditResults($intake, $audit);
            }
        }

        $this->displaySummary($totalAudited, $totalIssues, $summaryStats);

        return 0;
    }

    private function getIntakesToAudit()
    {
        if ($intakeId = $this->argument('intake')) {
            return Intake::where('id', $intakeId)->get();
        }

        if ($this->option('all')) {
            return Intake::with(['extraction', 'documents.extraction'])->get();
        }

        $hours = (int) $this->option('recent');
        return Intake::with(['extraction', 'documents.extraction'])
            ->where('created_at', '>=', now()->subHours($hours))
            ->get();
    }

    private function auditIntake(Intake $intake): array
    {
        try {
            return $this->exportService->getExportAudit($intake);
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'intake' => [
                    'id' => $intake->id,
                    'customer_name' => $intake->customer_name,
                ],
                'extraction_summary' => ['error' => 'Failed to get extraction data'],
                'payload_summary' => ['error' => 'Failed to generate payload'],
                'mapping_completeness' => ['error' => 'Failed to analyze mapping'],
            ];
        }
    }

    private function displayAuditResults(Intake $intake, array $audit): void
    {
        $status = isset($audit['error']) ? '❌' : '✅';
        $this->line("$status <fg=cyan>Intake #{$intake->id}</> - {$intake->customer_name}");

        if (isset($audit['error'])) {
            $this->error("   Error: {$audit['error']}");
            $this->newLine();
            return;
        }

        // Intake summary
        $exportStatus = $audit['intake']['robaws_quotation_id'] ? 
            "<fg=green>Exported (#{$audit['intake']['robaws_quotation_id']})</>" : 
            '<fg=yellow>Not exported</>';
        
        $this->line("   Export Status: $exportStatus");
        
        if ($audit['intake']['last_export_error']) {
            $this->line("   <fg=red>Last Error:</> {$audit['intake']['last_export_error']}");
        }

        // Extraction summary
        $extractionSize = $this->formatBytes($audit['extraction_summary']['total_size']);
        $this->line("   <fg=blue>Extraction:</> {$extractionSize} across " . 
                   count($audit['extraction_summary']['sections']) . " sections");

        if ($this->option('detailed')) {
            $this->displayExtractionDetails($audit['extraction_summary']);
        }

        // Payload summary
        $payloadSize = $this->formatBytes($audit['payload_summary']['total_size']);
        $hasJson = $audit['payload_summary']['has_json_field'] ? 
            '<fg=green>✓</>' : '<fg=red>✗</>';
        
        $this->line("   <fg=blue>Payload:</> {$payloadSize}, JSON field: $hasJson");

        // Mapping completeness
        $this->displayMappingCompleteness($audit['mapping_completeness']);

        // Show payload dump if requested
        if ($this->option('dump')) {
            $this->displayPayloadDump($audit);
        }

        $this->newLine();
    }

    private function displayExtractionDetails(array $extractionSummary): void
    {
        $indicators = [
            'vehicle' => $extractionSummary['has_vehicle'] ?? false,
            'shipping' => $extractionSummary['has_shipping'] ?? false,
            'contact' => $extractionSummary['has_contact'] ?? false,
        ];

        $statusLine = '';
        foreach ($indicators as $type => $hasData) {
            $icon = $hasData ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $statusLine .= "      {$icon} {$type}";
        }
        
        $this->line($statusLine);
    }

    private function displayMappingCompleteness(array $completeness): void
    {
        foreach ($completeness as $section => $stats) {
            if (isset($stats['error'])) {
                $this->line("      <fg=red>$section:</> Error - {$stats['error']}");
                continue;
            }

            $percent = $stats['completeness_percent'];
            $color = $percent >= 70 ? 'green' : ($percent >= 40 ? 'yellow' : 'red');
            
            $this->line("      <fg=$color>$section:</> {$percent}% " . 
                       "({$stats['filled_fields']}/{$stats['total_fields']} fields)");
        }
    }

    private function displayPayloadDump(array $audit): void
    {
        $this->newLine();
        $this->line('   <fg=yellow>🔍 Raw Payload Sections:</>');
        
        $sections = $audit['payload_summary']['sections'] ?? [];
        foreach ($sections as $section) {
            $this->line("      • $section");
        }

        if (!empty($audit['payload_summary']['has_json_field'])) {
            $jsonSize = $this->formatBytes($audit['payload_summary']['json_size']);
            $this->line("   <fg=yellow>📄 JSON Field Size:</> $jsonSize");
        }
    }

    private function hasSignificantIssues(array $audit): bool
    {
        // Has error
        if (isset($audit['error'])) {
            return true;
        }

        // No JSON field
        if (empty($audit['payload_summary']['has_json_field'])) {
            return true;
        }

        // Low mapping completeness
        foreach ($audit['mapping_completeness'] as $stats) {
            if (isset($stats['completeness_percent']) && $stats['completeness_percent'] < 30) {
                return true;
            }
        }

        return false;
    }

    private function collectStats(array $audit, array &$summaryStats): void
    {
        if (isset($audit['error'])) {
            $summaryStats['errors'] = ($summaryStats['errors'] ?? 0) + 1;
            return;
        }

        $summaryStats['exported'] = ($summaryStats['exported'] ?? 0) + 
            ($audit['intake']['robaws_quotation_id'] ? 1 : 0);
        
        $summaryStats['has_json'] = ($summaryStats['has_json'] ?? 0) + 
            ($audit['payload_summary']['has_json_field'] ? 1 : 0);

        foreach ($audit['mapping_completeness'] as $section => $stats) {
            if (isset($stats['completeness_percent'])) {
                $summaryStats['completeness'][$section][] = $stats['completeness_percent'];
            }
        }
    }

    private function displaySummary(int $totalAudited, int $totalIssues, array $summaryStats): void
    {
        $this->info('📊 Summary');
        $this->table(['Metric', 'Value'], [
            ['Total Audited', $totalAudited],
            ['With Issues', $totalIssues],
            ['Successfully Exported', $summaryStats['exported'] ?? 0],
            ['Has JSON Field', $summaryStats['has_json'] ?? 0],
        ]);

        if (!empty($summaryStats['completeness'])) {
            $this->newLine();
            $this->info('📈 Average Completeness by Section');
            
            $completenessTable = [];
            foreach ($summaryStats['completeness'] as $section => $percentages) {
                $avg = round(array_sum($percentages) / count($percentages), 1);
                $completenessTable[] = [$section, "{$avg}%"];
            }
            
            $this->table(['Section', 'Avg Completeness'], $completenessTable);
        }

        // Recommendations
        $this->newLine();
        $this->info('💡 Recommendations');
        
        if ($totalIssues > 0) {
            $this->line("• Fix {$totalIssues} intake(s) with significant mapping issues");
        }
        
        $missingJson = $totalAudited - ($summaryStats['has_json'] ?? 0);
        if ($missingJson > 0) {
            $this->line("• {$missingJson} intake(s) missing JSON field - check automation mapping");
        }
        
        $notExported = $totalAudited - ($summaryStats['exported'] ?? 0);
        if ($notExported > 0) {
            $this->line("• {$notExported} intake(s) not yet exported to Robaws");
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
