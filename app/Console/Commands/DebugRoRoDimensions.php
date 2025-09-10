<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ExtractionService;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Models\Document;
use App\Services\AiRouter;

class DebugRoRoDimensions extends Command
{
    protected $signature = 'debug:roro-dimensions';
    protected $description = 'Debug RO-RO email dimensions extraction';

    public function handle()
    {
        $this->info('=== RO-RO Dimensions Debugging ===');
        $this->newLine();

        // Create a test document for the RO-RO email
        $document = new Document([
            'filename' => 'RO-RO verscheping ANTWERPEN - MOMBASA, KENIA.eml',
            'mime_type' => 'message/rfc822',
            'file_path' => base_path('RO-RO verscheping ANTWERPEN - MOMBASA, KENIA.eml')
        ]);

        $this->info('1. Email Content Analysis:');
        $this->line('File: ' . $document->filename);
        $this->newLine();

        // Check if file exists
        if (!file_exists($document->file_path)) {
            $this->error('Email file not found: ' . $document->file_path);
            return 1;
        }

        $this->info('Email contains dimensions:');
        $this->line('- L390 cm (Length)');
        $this->line('- B230 cm (Width)'); 
        $this->line('- H310cm (Height)');
        $this->line('- 3500KG (Weight)');
        $this->newLine();

        $this->info('2. Testing Email Extraction:');
        try {
            $extractionService = app(ExtractionService::class);
            $emailStrategy = new EmailExtractionStrategy(
                app(AiRouter::class), 
                app(\App\Services\Extraction\HybridExtractionPipeline::class)
            );
            
            if ($emailStrategy->supports($document)) {
                $this->info('✓ Email strategy supports this document');
                
                // Extract data
                $result = $emailStrategy->extract($document);
                
                if ($result && $result->isSuccessful()) {
                    $extractedData = $result->getData();
                    $this->info('✓ Extraction successful');
                    $this->newLine();
                    
                    // Look for dimensions in various paths
                    $this->info('3. Dimension Analysis:');
                    
                    // Check vehicle dimensions
                    if (isset($extractedData['vehicle']['dimensions'])) {
                        $dims = $extractedData['vehicle']['dimensions'];
                        $this->info('Vehicle dimensions found:');
                        $this->line(json_encode($dims, JSON_PRETTY_PRINT));
                    } else {
                        $this->error('❌ No vehicle dimensions found in structured format');
                    }
                    
                    // Check raw dimensions
                    if (isset($extractedData['raw_data']['dimensions'])) {
                        $this->info('Raw dimensions: ' . $extractedData['raw_data']['dimensions']);
                    } else {
                        $this->error('❌ No raw dimensions found');
                    }
                    
                    // Check for dimensions string
                    if (isset($extractedData['dimensions'])) {
                        $this->info('Direct dimensions: ' . $extractedData['dimensions']);
                    } else {
                        $this->error('❌ No direct dimensions found');
                    }
                    
                    $this->newLine();
                    $this->info('4. Full Extracted Data Structure:');
                    $this->line(json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                } else {
                    $this->error('❌ Extraction failed');
                    if ($result) {
                        $this->error('Error: ' . $result->getErrorMessage());
                    }
                }
            } else {
                $this->error('❌ Email strategy doesn\'t support this document');
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }

        return 0;
    }
}
