<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimpleRobawsIntegration;
use Illuminate\Console\Command;

class DebugRobawsMapping extends Command
{
    protected $signature = 'robaws:debug-mapping {document_id?}';
    protected $description = 'Debug Robaws mapping for a document';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        
        if (!$documentId) {
            // Get latest document with extraction
            $document = Document::whereHas('extractions')->latest()->first();
        } else {
            $document = Document::find($documentId);
        }
        
        if (!$document) {
            $this->error('No document found');
            return;
        }
        
        $this->info("Processing Document ID: {$document->id} - {$document->filename}");
        
        // Check extraction
        $extraction = $document->extractions()->first();
        if (!$extraction) {
            $this->error('No extraction found for this document');
            return;
        }
        
        $this->info("Extraction Status: {$extraction->status}");
        $this->info("Extraction Service: {$extraction->service_used}");
        
        // Get extracted data
        $extractedData = $extraction->extracted_data;
        if (!$extractedData) {
            $this->error('No extracted data found');
            return;
        }
        
        $this->info("Extracted Data Keys: " . implode(', ', array_keys($extractedData)));
        
        // Show some sample data
        if (isset($extractedData['contact'])) {
            $this->info("Contact Name: " . ($extractedData['contact']['name'] ?? 'NOT SET'));
        }
        if (isset($extractedData['vehicle'])) {
            $this->info("Vehicle: " . ($extractedData['vehicle']['brand'] ?? 'NOT SET') . ' ' . ($extractedData['vehicle']['model'] ?? 'NOT SET'));
        }
        if (isset($extractedData['shipment'])) {
            $this->info("Route: " . ($extractedData['shipment']['origin'] ?? 'NOT SET') . ' → ' . ($extractedData['shipment']['destination'] ?? 'NOT SET'));
        }
        
        // Try the Robaws mapping
        $this->info("\nApplying Robaws mapping...");
        
        $robawsIntegration = new SimpleRobawsIntegration();
        
        try {
            $result = $robawsIntegration->storeExtractedDataForRobaws($document, $extractedData);
            
            if ($result) {
                $this->info("✓ Mapping successful!");
                
                // Reload document
                $document->refresh();
                
                if ($document->robaws_quotation_data) {
                    $this->info("\nRobaws data stored successfully");
                    $this->info("Key fields mapped:");
                    
                    $robawsData = $document->robaws_quotation_data;
                    $this->table(
                        ['Field', 'Value'],
                        [
                            ['Customer', $robawsData['customer'] ?? 'NOT SET'],
                            ['Customer Ref', $robawsData['customer_reference'] ?? 'NOT SET'],
                            ['POR', $robawsData['por'] ?? 'NOT SET'],
                            ['POL', $robawsData['pol'] ?? 'NOT SET'],
                            ['POD', $robawsData['pod'] ?? 'NOT SET'],
                            ['Cargo', $robawsData['cargo'] ?? 'NOT SET'],
                            ['Dimensions', $robawsData['dim_bef_delivery'] ?? 'NOT SET'],
                        ]
                    );
                } else {
                    $this->error("Robaws data not found after mapping");
                }
            } else {
                $this->error("✗ Mapping failed!");
            }
            
        } catch (\Exception $e) {
            $this->error("Exception during mapping: " . $e->getMessage());
            $this->error("Stack trace:");
            $this->error($e->getTraceAsString());
        }
    }
}
