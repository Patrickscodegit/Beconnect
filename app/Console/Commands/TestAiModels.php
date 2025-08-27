<?php

namespace App\Console\Commands;

use App\Services\AiRouter;
use App\Helpers\FileInput;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestAiModels extends Command
{
    protected $signature = 'test:ai-models';
    protected $description = 'Test AI models being used in extraction';

    public function handle(AiRouter $aiRouter)
    {
        $this->info('=== Testing AI Models in Extraction ===');
        
        // Get latest image document
        $document = Document::where('mime_type', 'like', 'image/%')
            ->latest()
            ->first();
            
        if (!$document) {
            $this->error('No image document found');
            return 1;
        }
        
        $this->line("Testing with Document ID: {$document->id}");
        $this->line("Filename: {$document->filename}");
        
        // Clear existing logs to see fresh output
        $this->info("\n🔍 Watching logs for model usage...");
        
        try {
            $fileInput = FileInput::forExtractor(
                $document->file_path,
                $document->mime_type ?? 'image/png'
            );
            
            // Test extraction and capture logs
            $result = $aiRouter->extractAdvanced($fileInput, 'shipping');
            
            $this->info("✅ Extraction completed");
            $this->line("Status: " . ($result['status'] ?? 'unknown'));
            
            // Show what data was extracted
            if (isset($result['extracted_data']) && !isset($result['extracted_data']['error'])) {
                $this->info("✅ Successfully extracted data with keys: " . implode(', ', array_keys($result['extracted_data'])));
                
                // Check confidence
                $confidence = $result['metadata']['confidence_score'] ?? 0;
                $this->line("Confidence: " . ($confidence * 100) . "%");
                
                if ($confidence > 0.8) {
                    $this->info("🎉 High confidence extraction - model working well!");
                } elseif ($confidence > 0.5) {
                    $this->warn("⚠️  Medium confidence - model working but could be better");
                } else {
                    $this->error("❌ Low confidence - model may have issues");
                }
            } else {
                $this->error("❌ Extraction failed or returned error");
            }
            
            // Check logs
            $this->info("\n📋 Check the Laravel logs for model usage details:");
            $this->line("tail -n 20 storage/logs/laravel.log | grep -E 'model|vision|OpenAI'");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Exception: " . $e->getMessage());
            return 1;
        }
    }
}
