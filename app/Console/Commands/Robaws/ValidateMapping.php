<?php

namespace App\Console\Commands\Robaws;

use Illuminate\Console\Command;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsIntegration\JsonFieldMapper;
use App\Models\Document;

class ValidateMapping extends Command
{
    protected $signature = 'robaws:validate-mapping {--recent=10 : Number of recent documents to test}';
    protected $description = 'Validate field mapping configuration against recent documents';

    public function handle(EnhancedRobawsIntegrationService $svc, JsonFieldMapper $mapper): int
    {
        $this->info('🔍 Validating Robaws field mapping configuration...');
        
        // Test mapping configuration
        $mappingInfo = $mapper->getMappingSummary();
        $this->line("📋 Mapping Configuration:");
        $this->line("  Version: {$mappingInfo['version']}");
        $this->line("  Sections: " . implode(', ', $mappingInfo['sections']));
        $this->line("  Total fields: {$mappingInfo['total_fields']}");
        
        foreach ($mappingInfo['fields_by_section'] as $section => $count) {
            $this->line("  - {$section}: {$count} fields");
        }
        
        $this->newLine();
        
        // Test against recent documents
        $limit = (int) $this->option('recent');
        $this->info("🧪 Testing mapping against {$limit} recent documents...");
        
        $docs = Document::whereHas('extractions', function ($q) {
            $q->where('status', 'completed');
        })->latest()->limit($limit)->get();
        
        if ($docs->isEmpty()) {
            $this->warn('No documents found with completed extractions.');
            return self::SUCCESS;
        }
        
        $results = [
            'total' => 0,
            'valid' => 0,
            'warnings' => 0,
            'errors' => 0,
            'missing_fields' => [],
        ];
        
        foreach ($docs as $doc) {
            $extraction = $doc->extractions()->latest()->first();
            if (!$extraction || !$extraction->extracted_data) {
                continue;
            }
            
            $extractedData = is_array($extraction->extracted_data) 
                ? $extraction->extracted_data 
                : json_decode($extraction->extracted_data, true);
            
            try {
                $mapped = $mapper->mapFields($extractedData);
                $results['total']++;
                
                // Validate mapped data
                $validation = $this->validateMappedData($mapped);
                
                if ($validation['is_valid']) {
                    $results['valid']++;
                } else {
                    $results['errors']++;
                    foreach ($validation['missing'] as $missing) {
                        $results['missing_fields'][$missing] = ($results['missing_fields'][$missing] ?? 0) + 1;
                    }
                }
                
                if (!empty($validation['warnings'])) {
                    $results['warnings']++;
                }
                
            } catch (\Exception $e) {
                $results['errors']++;
                $this->error("Document {$doc->id}: " . $e->getMessage());
            }
        }
        
        // Show results
        $this->newLine();
        $this->info('📊 Validation Results:');
        $this->line("  Documents tested: {$results['total']}");
        $this->line("  Valid mappings: {$results['valid']}");
        $this->line("  With warnings: {$results['warnings']}");
        $this->line("  With errors: {$results['errors']}");
        
        if (!empty($results['missing_fields'])) {
            $this->newLine();
            $this->warn('❌ Most common missing fields:');
            arsort($results['missing_fields']);
            foreach (array_slice($results['missing_fields'], 0, 5, true) as $field => $count) {
                $this->line("  - {$field}: missing in {$count} documents");
            }
        }
        
        $successRate = $results['total'] > 0 ? round(($results['valid'] / $results['total']) * 100, 1) : 0;
        $this->newLine();
        $this->info("✅ Success rate: {$successRate}%");
        
        return $results['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
    
    private function validateMappedData(array $mapped): array
    {
        $required = config('robaws.validation.required_fields', ['customer', 'por', 'pod', 'cargo']);
        $missing = array_filter($required, fn($field) => empty($mapped[$field] ?? null));
        
        $warnings = [];
        if (empty($mapped['client_email'])) {
            $warnings[] = 'Missing client email';
        }
        if (empty($mapped['customer_reference'])) {
            $warnings[] = 'Missing customer reference';
        }
        
        return [
            'is_valid' => empty($missing),
            'missing' => $missing,
            'warnings' => $warnings,
        ];
    }
}
