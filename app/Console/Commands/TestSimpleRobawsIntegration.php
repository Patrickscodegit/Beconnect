<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimpleRobawsIntegration;
use Illuminate\Console\Command;

class TestSimpleRobawsIntegration extends Command
{
    protected $signature = 'robaws:test-simple {--document-id= : Test with specific document ID}';
    protected $description = 'Test the simple Robaws integration with extracted document data';

    public function handle()
    {
        $this->info('🔧 Testing Simple Robaws Integration...');
        $this->newLine();

        $robawsIntegration = app(SimpleRobawsIntegration::class);

        // Get integration summary
        $this->info('📊 Integration Summary:');
        $summary = $robawsIntegration->getIntegrationSummary();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Documents', $summary['total_documents']],
                ['Ready for Sync', $summary['ready_for_sync']],
                ['Already Synced', $summary['synced']],
                ['Pending Formatting', $summary['pending_formatting']],
                ['Latest Ready', $summary['latest_ready'] ?? 'None'],
            ]
        );

        $this->newLine();

        // Test with specific document or find one
        $documentId = $this->option('document-id');
        
        if ($documentId) {
            $document = Document::find($documentId);
            if (!$document) {
                $this->error("Document with ID {$documentId} not found.");
                return Command::FAILURE;
            }
        } else {
            // Find a document with extraction data
            $document = Document::whereNotNull('extraction_data')
                              ->where('extraction_status', 'completed')
                              ->latest()
                              ->first();
            
            if (!$document) {
                $this->error('No documents with completed extractions found.');
                $this->line('Please upload and process a document first.');
                return Command::FAILURE;
            }
        }

        $this->info("📄 Testing with document: {$document->filename} (ID: {$document->id})");
        
        // Check if document has extraction data
        if (!$document->extraction_data) {
            $this->error('Document has no extraction data.');
            return Command::FAILURE;
        }

        $this->line('Extraction data preview:');
        $extractedData = is_string($document->extraction_data) 
            ? json_decode($document->extraction_data, true) 
            : $document->extraction_data;
        
        $this->line(json_encode($extractedData, JSON_PRETTY_PRINT));
        $this->newLine();

        // Test formatting for Robaws
        $this->info('🔄 Formatting data for Robaws...');
        $success = $robawsIntegration->storeExtractedDataForRobaws($document, $extractedData);

        if ($success) {
            $this->info('✅ Data successfully formatted for Robaws!');
            
            // Show the formatted data
            $document->refresh();
            if ($document->robaws_json_data) {
                $this->line('Robaws-formatted data:');
                $this->line(json_encode($document->robaws_json_data, JSON_PRETTY_PRINT));
                
                $this->newLine();
                $this->info('📤 Export data for manual Robaws import:');
                $exportData = $robawsIntegration->exportDocumentForRobaws($document);
                $this->line(json_encode($exportData, JSON_PRETTY_PRINT));
            }
        } else {
            $this->error('❌ Failed to format data for Robaws.');
            return Command::FAILURE;
        }

        $this->newLine();

        // Test export functionality
        $this->info('📦 Testing export functionality...');
        $readyDocuments = $robawsIntegration->getDocumentsReadyForExport();
        $this->line("Found {$readyDocuments->count()} documents ready for export.");

        if ($readyDocuments->count() > 0) {
            $this->info('🗂️  Documents ready for Robaws sync:');
            $tableData = [];
            foreach ($readyDocuments->take(5) as $doc) {
                $tableData[] = [
                    $doc->id,
                    substr($doc->filename, 0, 30) . (strlen($doc->filename) > 30 ? '...' : ''),
                    $doc->robaws_formatted_at?->format('Y-m-d H:i:s') ?? 'Unknown',
                    $doc->robaws_sync_status ?? 'Unknown'
                ];
            }
            
            $this->table(
                ['ID', 'Filename', 'Formatted At', 'Status'],
                $tableData
            );

            if ($readyDocuments->count() > 5) {
                $this->line("... and " . ($readyDocuments->count() - 5) . " more documents.");
            }
        }

        $this->newLine();
        $this->info('🏁 Integration test completed successfully!');
        $this->line('💡 Next steps:');
        $this->line('   1. Use the export data above to manually create quotations in Robaws');
        $this->line('   2. Mark documents as synced using: php artisan robaws:mark-synced [document-id] [robaws-quotation-id]');

        return Command::SUCCESS;
    }
}
