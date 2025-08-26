<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimpleRobawsIntegration;
use Illuminate\Console\Command;

class MarkRobawsSynced extends Command
{
    protected $signature = 'robaws:mark-synced {document-id} {robaws-quotation-id?}';
    protected $description = 'Mark a document as synced with Robaws';

    public function handle()
    {
        $documentId = $this->argument('document-id');
        $robawsQuotationId = $this->argument('robaws-quotation-id');

        $document = Document::find($documentId);
        
        if (!$document) {
            $this->error("Document with ID {$documentId} not found.");
            return Command::FAILURE;
        }

        $this->info("Marking document as synced: {$document->filename}");
        
        if ($robawsQuotationId) {
            $this->line("Robaws Quotation ID: {$robawsQuotationId}");
        }

        $robawsIntegration = app(SimpleRobawsIntegration::class);
        $success = $robawsIntegration->markAsManuallySynced($document, $robawsQuotationId);

        if ($success) {
            $this->info('✅ Document marked as synced successfully!');
            
            $document->refresh();
            $this->table(
                ['Field', 'Value'],
                [
                    ['Document ID', $document->id],
                    ['Filename', $document->filename],
                    ['Sync Status', $document->robaws_sync_status],
                    ['Robaws Quotation ID', $document->robaws_quotation_id ?? 'Not set'],
                    ['Synced At', $document->robaws_synced_at?->format('Y-m-d H:i:s') ?? 'Not set'],
                ]
            );
        } else {
            $this->error('❌ Failed to mark document as synced.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
