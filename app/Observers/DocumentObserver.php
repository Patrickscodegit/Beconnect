<?php

namespace App\Observers;

use App\Models\Document;
use App\Jobs\ExtractDocumentData;
use App\Jobs\ProcessEmailDocument;

class DocumentObserver
{
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        // Check if this is an email file (.eml)
        if ($this->isEmailFile($document)) {
            // Use specialized email processing job
            dispatch_sync(new ProcessEmailDocument($document));
        } else {
            // Use regular document extraction job
            dispatch_sync(new ExtractDocumentData($document));
        }
    }

    /**
     * Check if the document is an email file
     */
    private function isEmailFile(Document $document): bool
    {
        $mimeType = $document->mime_type ?? '';
        $extension = strtolower(pathinfo($document->filename, PATHINFO_EXTENSION));
        
        return $mimeType === 'message/rfc822' ||
               $mimeType === 'application/vnd.ms-outlook' ||
               $extension === 'eml' ||
               $extension === 'msg';
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
