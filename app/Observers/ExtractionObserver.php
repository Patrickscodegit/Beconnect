<?php

namespace App\Observers;

use App\Models\Extraction;
use App\Jobs\UploadDocumentToRobaws;
use Illuminate\Support\Facades\Log;

class ExtractionObserver
{
    /**
     * Handle the Extraction "created" event.
     */
    public function created(Extraction $extraction): void
    {
        // Nothing needed here
    }

    /**
     * Handle the Extraction "updated" event.
     */
    public function updated(Extraction $extraction): void
    {
        // Check if Robaws quotation was just created
        if ($extraction->wasChanged('robaws_quotation_id') && 
            $extraction->robaws_quotation_id && 
            $extraction->document) {
            
            Log::info('Robaws quotation created, uploading document to same quotation', [
                'extraction_id' => $extraction->id,
                'quotation_id' => $extraction->robaws_quotation_id,
                'document_id' => $extraction->document_id
            ]);

            // Upload document to the SAME quotation that was just created
            UploadDocumentToRobaws::dispatch(
                $extraction->document, 
                $extraction->robaws_quotation_id
            )->delay(now()->addSeconds(5)); // Small delay to ensure quotation is fully created
        }
    }
}
