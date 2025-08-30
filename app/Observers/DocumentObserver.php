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
        //
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
