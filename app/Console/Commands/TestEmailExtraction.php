<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailDocument;
use App\Models\Document;
use App\Services\EmailParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestEmailExtraction extends Command
{
    protected $signature = 'test:email-extraction {document_id? : Document ID to test}';
    protected $description = 'Test email extraction with improved vehicle detection';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        
        if ($documentId) {
            $document = Document::find($documentId);
            if (!$document) {
                $this->error("Document with ID {$documentId} not found.");
                return Command::FAILURE;
            }
        } else {
            // Find the latest email document
            $document = Document::whereIn('mime_type', ['message/rfc822', 'application/vnd.ms-outlook'])
                               ->latest()
                               ->first();
            
            if (!$document) {
                $this->error('No email documents found.');
                return Command::FAILURE;
            }
        }

        $this->info("Testing extraction for document: {$document->filename} (ID: {$document->id})");
        $this->info("MIME Type: {$document->mime_type}");
        $this->newLine();
        
        // Parse the email
        $parser = app(EmailParserService::class);
        
        try {
            $emailData = $parser->parseEmlFile($document);
            
            $this->info("📧 Email Details:");
            $this->table(['Field', 'Value'], [
                ['Subject', $emailData['subject'] ?? 'N/A'],
                ['From', $emailData['from'] ?? 'N/A'],
                ['To', $emailData['to'] ?? 'N/A'],
                ['Date', $emailData['date'] ?? 'N/A'],
                ['Has Text', !empty($emailData['text']) ? 'YES' : 'NO'],
                ['Has HTML', !empty($emailData['html']) ? 'YES' : 'NO'],
                ['Attachments', count($emailData['attachments'] ?? [])],
            ]);
            
            $this->newLine();
            
            // Test vehicle detection
            $this->info("🚗 Vehicle Detection Test:");
            $detectionMethod = new \ReflectionMethod($parser, 'detectVehicleInformation');
            $detectionMethod->setAccessible(true);
            $vehicleInfo = $detectionMethod->invoke($parser, $emailData);
            
            if (!empty($vehicleInfo)) {
                $this->info("✅ Vehicle information detected:");
                foreach ($vehicleInfo as $key => $value) {
                    $this->line("  {$key}: {$value}");
                }
            } else {
                $this->warn("⚠️  No vehicle information detected in pre-processing");
            }
            
            $this->newLine();
            
            // Show content being sent to AI
            $this->info("📄 Content prepared for AI extraction:");
            $shippingContent = $parser->extractShippingContent($emailData);
            $this->line("Content length: " . strlen($shippingContent) . " characters");
            
            if ($this->confirm('Show full content being sent to AI?', false)) {
                $this->line("--- CONTENT START ---");
                $this->line($shippingContent);
                $this->line("--- CONTENT END ---");
                $this->newLine();
            }
            
            // Process with AI
            $this->info("🤖 Processing with AI extraction...");
            
            // Clear existing extractions for this test
            $document->extractions()->delete();
            
            ProcessEmailDocument::dispatchSync($document);
            
            // Check results
            $document->refresh();
            $extraction = $document->extractions()->latest()->first();
            
            if ($extraction && $extraction->extracted_data) {
                $this->info("✅ Extraction completed!");
                $this->info("Confidence: " . ($extraction->confidence_score * 100) . "%");
                $this->info("Status: " . $extraction->status);
                
                $data = $extraction->extracted_data;
                
                $this->newLine();
                $this->info("🚗 Extracted Vehicle Information:");
                if (isset($data['vehicle']) && !empty(array_filter($data['vehicle']))) {
                    foreach ($data['vehicle'] as $key => $value) {
                        if (!empty($value) && !is_array($value)) {
                            $this->line("  {$key}: {$value}");
                        } elseif ($key === 'dimensions' && is_array($value)) {
                            $this->line("  dimensions:");
                            foreach ($value as $dimKey => $dimValue) {
                                $this->line("    {$dimKey}: {$dimValue}");
                            }
                        }
                    }
                    
                    // Show database match info if available
                    if (isset($data['vehicle']['database_match']) && $data['vehicle']['database_match']) {
                        $this->info("✅ Database match found!");
                        if (isset($data['vehicle']['weight_kg'])) {
                            $this->line("  Database weight: {$data['vehicle']['weight_kg']} kg");
                        }
                        if (isset($data['vehicle']['dimensions'])) {
                            $dims = $data['vehicle']['dimensions'];
                            $this->line("  Database dimensions: {$dims['length_m']}L x {$dims['width_m']}W x {$dims['height_m']}H m");
                        }
                    }
                } else {
                    $this->warn("⚠️  No vehicle information extracted by AI");
                }
                
                $this->newLine();
                $this->info("📦 Extracted Shipment Information:");
                if (isset($data['shipment']) && !empty(array_filter($data['shipment']))) {
                    foreach ($data['shipment'] as $key => $value) {
                        if (!empty($value)) {
                            $this->line("  {$key}: {$value}");
                        }
                    }
                } else {
                    $this->warn("⚠️  No shipment information extracted");
                }
                
                $this->newLine();
                $this->info("👤 Extracted Contact Information:");
                if (isset($data['contact']) && !empty(array_filter($data['contact']))) {
                    foreach ($data['contact'] as $key => $value) {
                        if (!empty($value)) {
                            $this->line("  {$key}: {$value}");
                        }
                    }
                } else {
                    $this->warn("⚠️  No contact information extracted");
                }
                
                if ($this->confirm('Show full extracted data?', false)) {
                    $this->newLine();
                    $this->info("📋 Full Extracted Data:");
                    $this->line(json_encode($data, JSON_PRETTY_PRINT));
                }
                
            } else {
                $this->error("❌ Extraction failed or no data extracted");
                if ($extraction && $extraction->error_message) {
                    $this->error("Error: " . $extraction->error_message);
                }
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Failed to parse email: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
}
