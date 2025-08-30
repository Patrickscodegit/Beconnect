<?php

namespace App\Observers;

use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use Illuminate\Support\Facades\Log;

class DocumentObserver
{
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        // Only dispatch extraction for standalone documents
        if (!$document->intake_id) {
            Log::info('Dispatching extraction job for standalone document', [
                'document_id' => $document->id
            ]);
            
            // Use async dispatch to prevent blocking
            ExtractDocumentData::dispatch($document);
        }
    }

    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        // Check if Robaws quotation was just created
        if ($document->wasChanged('robaws_quotation_id') && $document->robaws_quotation_id) {
            Log::info('Robaws quotation added to document, uploading file', [
                'document_id' => $document->id,
                'quotation_id' => $document->robaws_quotation_id,
                'filename' => $document->filename
            ]);

            // Upload document to the quotation
            \App\Jobs\UploadDocumentToRobaws::dispatch(
                $document, 
                $document->robaws_quotation_id
            )->delay(now()->addSeconds(5)); // Small delay to ensure quotation is fully created
        }
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "force deleted" event.
     */
    public function forceDeleted(Document $document): void
    {
        //
    }
}
