<?php

namespace App\Console\Commands;

use App\Models\Extraction;
use Illuminate\Console\Command;

class DebugExtraction extends Command
{
    protected $signature = 'debug:extraction {id}';
    protected $description = 'Debug extraction data display issues';

    public function handle()
    {
        $id = $this->argument('id');
        $extraction = Extraction::find($id);
        
        if (!$extraction) {
            $this->error("Extraction ID {$id} not found");
            return;
        }
        
        $this->info("Extraction ID: {$extraction->id}");
        $this->info("Status: {$extraction->status}");
        
        // Check extracted_data
        $this->info("\n--- Extracted Data ---");
        $this->info("Type: " . gettype($extraction->extracted_data));
        $this->info("Is Empty: " . (empty($extraction->extracted_data) ? 'Yes' : 'No'));
        $this->info("Is Null: " . (is_null($extraction->extracted_data) ? 'Yes' : 'No'));
        
        if ($extraction->extracted_data) {
            $this->info("Content:");
            $this->line(json_encode($extraction->extracted_data, JSON_PRETTY_PRINT));
        }
        
        // Check raw_json
        $this->info("\n--- Raw JSON ---");
        $this->info("Type: " . gettype($extraction->raw_json));
        $this->info("Length: " . strlen($extraction->raw_json ?? ''));
        $this->info("Is Empty: " . (empty($extraction->raw_json) ? 'Yes' : 'No'));
        
        if ($extraction->raw_json) {
            $this->info("First 500 chars:");
            $this->line(substr($extraction->raw_json, 0, 500));
        }
        
        // Check model casts
        $this->info("\n--- Model Casts ---");
        $casts = $extraction->getCasts();
        $this->info("extracted_data cast: " . ($casts['extracted_data'] ?? 'not set'));
        $this->info("raw_json cast: " . ($casts['raw_json'] ?? 'not set'));
        
        // Test visibility condition
        $this->info("\n--- Visibility Test ---");
        $this->info("!empty(\$extraction->extracted_data): " . (!empty($extraction->extracted_data) ? 'true' : 'false'));
        $this->info("!empty(\$extraction->raw_json): " . (!empty($extraction->raw_json) ? 'true' : 'false'));
    }
}
