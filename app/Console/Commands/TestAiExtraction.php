<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AiRouter;

class TestAiExtraction extends Command
{
    protected $signature = 'ai:test-extraction';
    protected $description = 'Test AI extraction pipeline with sample freight data';

    public function handle()
    {
        $this->info('🧪 Testing AI Extraction Pipeline...');
        $this->newLine();
        
        // Test text similar to freight forwarding document
        $testText = "Consignee: Belgaco Shipping Ltd\nAddress: Dubai Investment Park-1\nPhone: +971-4-8859876\nInvoice: INV-2025-001\nContainer: MSKU1234567\nPort of Loading: Shanghai\nPort of Discharge: Dubai";
        
        $schema = [
            'type' => 'object',
            'properties' => [
                'consignee' => ['type' => 'string', 'description' => 'Consignee name'],
                'address' => ['type' => 'string', 'description' => 'Consignee address'],
                'phone' => ['type' => 'string', 'description' => 'Phone number'],
                'invoice' => ['type' => 'string', 'description' => 'Invoice number'],
                'container' => ['type' => 'string', 'description' => 'Container number'],
                'port_of_loading' => ['type' => 'string', 'description' => 'Port of loading'],
                'port_of_discharge' => ['type' => 'string', 'description' => 'Port of discharge']
            ],
            'required' => ['consignee'],
            'additionalProperties' => false
        ];
        
        try {
            $this->info('📤 Sending to AI service...');
            
            $ai = app(AiRouter::class);
            $startTime = microtime(true);
            
            $result = $ai->extract($testText, $schema, ['cheap' => true, 'reasoning' => false]);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->info("✅ Extraction successful! ({$duration}ms)");
            $this->newLine();
            $this->info('📋 Extracted data:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Extraction failed: ' . $e->getMessage());
            $this->newLine();
            $this->warn('💡 Check your API keys and configuration');
            return 1;
        }
    }
}
