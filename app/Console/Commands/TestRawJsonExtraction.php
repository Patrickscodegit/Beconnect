<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Extraction;
use App\Jobs\ExtractDocumentData;
use Illuminate\Console\Command;

class TestRawJsonExtraction extends Command
{
    protected $signature = 'test:raw-json {--fix : Fix missing raw_json data for existing extractions}';
    protected $description = 'Test and fix raw JSON extraction storage';

    public function handle()
    {
        $this->info('=== Raw JSON Extraction Test ===');
        
        // Check completed extractions
        $extractions = Extraction::where('status', 'completed')->take(5)->get();
        
        $this->info("Found {$extractions->count()} completed extractions to examine:");
        $this->newLine();
        
        foreach ($extractions as $extraction) {
            $this->info("Extraction ID: {$extraction->id}");
            $this->info("Status: {$extraction->status}");
            $this->info("Service Used: {$extraction->service_used}");
            
            // Check raw_json
            $rawJsonExists = !empty($extraction->raw_json);
            $rawJsonType = gettype($extraction->raw_json);
            
            $this->info("Has raw_json: " . ($rawJsonExists ? 'Yes' : 'No'));
            $this->info("Raw JSON type: {$rawJsonType}");
            
            if ($rawJsonExists) {
                if (is_string($extraction->raw_json)) {
                    $length = strlen($extraction->raw_json);
                    $this->info("Raw JSON length: {$length} characters");
                    $this->info("First 100 chars: " . substr($extraction->raw_json, 0, 100) . '...');
                } else {
                    $this->info("Raw JSON content: " . json_encode($extraction->raw_json));
                }
            } else {
                $this->warn("No raw_json data found!");
                
                if ($this->option('fix')) {
                    $this->info("Attempting to fix...");
                    
                    // Create basic raw_json from extracted_data
                    if ($extraction->extracted_data) {
                        $basicRawJson = [
                            'extraction_id' => $extraction->id,
                            'timestamp' => $extraction->created_at->toIso8601String(),
                            'service_used' => $extraction->service_used ?? 'basic',
                            'data' => $extraction->extracted_data,
                            'note' => 'Raw JSON reconstructed from extracted_data'
                        ];
                        
                        $extraction->update([
                            'raw_json' => json_encode($basicRawJson, JSON_PRETTY_PRINT)
                        ]);
                        
                        $this->info("✓ Fixed: Added reconstructed raw_json");
                    } else {
                        $this->warn("✗ Cannot fix: No extracted_data available");
                    }
                }
            }
            
            $this->newLine();
        }
        
        // Test new extraction if requested
        if ($this->confirm('Would you like to test a new extraction?')) {
            $document = Document::whereDoesntHave('extractions')->first();
            
            if ($document) {
                $this->info("Testing extraction for document: {$document->file_name}");
                ExtractDocumentData::dispatch($document);
                $this->info("Extraction job dispatched. Check the results in a few seconds.");
            } else {
                $this->warn("No documents without extractions found for testing.");
            }
        }
    }
}
