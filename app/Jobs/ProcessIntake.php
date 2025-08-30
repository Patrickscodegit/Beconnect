<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Jobs\ExtractDocumentData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIntake implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $intake;

    /**
     * Create a new job instance.
     */
    public function __construct(Intake $intake)
    {
        $this->intake = $intake;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing intake', ['intake_id' => $this->intake->id]);

        try {
            // Get all documents for this intake
            $documents = $this->intake->documents;

            if ($documents->isEmpty()) {
                Log::warning('No documents found for intake', ['intake_id' => $this->intake->id]);
                return;
            }

            // Process each document for extraction
            foreach ($documents as $document) {
                if (!$document->extracted_data) {
                    Log::info('Dispatching extraction job for document', [
                        'intake_id' => $this->intake->id,
                        'document_id' => $document->id
                    ]);
                    
                    ExtractDocumentData::dispatch($document);
                }
            }

            Log::info('Intake processing completed', ['intake_id' => $this->intake->id]);

        } catch (\Exception $e) {
            Log::error('Error processing intake', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
