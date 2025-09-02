<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Jobs\ExtractDocumentData;
use App\Services\ExtractionService;
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
            // Get all files for this intake
            $files = $this->intake->files;

            if ($files->isEmpty()) {
                Log::warning('No files found for intake', ['intake_id' => $this->intake->id]);
                $this->intake->update(['status' => 'failed']);
                return;
            }

            // Start with existing extraction data
            $payload = (array) ($this->intake->extraction_data ?? []);

            // Extract data from each file and merge
            foreach ($files as $file) {
                Log::info('Processing file for extraction', [
                    'intake_id' => $this->intake->id,
                    'file_id' => $file->id,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type
                ]);

                $fileData = app(ExtractionService::class)->extractFromFile($file);
                if ($fileData) {
                    // Deep merge file data into payload
                    $payload = array_replace_recursive($payload, $fileData);
                }
            }

            // Merge contact data: seeded data takes precedence, extracted fills gaps
            $contactData = array_merge(
                (array) data_get($payload, 'contact', []),
                array_filter([
                    'name' => $this->intake->customer_name,
                    'email' => $this->intake->contact_email,
                    'phone' => $this->intake->contact_phone,
                ])
            );

            // Update contact in payload and flat columns
            $payload['contact'] = $contactData;
            
            $this->intake->update([
                'extraction_data' => $payload,
                'customer_name' => $contactData['name'] ?? $this->intake->customer_name,
                'contact_email' => $contactData['email'] ?? $this->intake->contact_email,
                'contact_phone' => $contactData['phone'] ?? $this->intake->contact_phone,
                'status' => 'processed'
            ]);

            // Check if ready for export (has contact info)
            $hasEmail = !empty($contactData['email']);
            $hasPhone = !empty($contactData['phone']);

            if ($hasEmail || $hasPhone) {
                Log::info('Contact info sufficient - ready for export', [
                    'intake_id' => $this->intake->id,
                    'has_email' => $hasEmail,
                    'has_phone' => $hasPhone
                ]);
                
                // Dispatch export job
                dispatch(new \App\Jobs\ExportIntakeToRobawsJob($this->intake->id));
            } else {
                Log::info('Contact info insufficient - marking as needs_contact', [
                    'intake_id' => $this->intake->id
                ]);
                
                $this->intake->update(['status' => 'needs_contact']);
            }

            Log::info('Intake processing completed', [
                'intake_id' => $this->intake->id,
                'files_processed' => $files->count(),
                'ready_for_export' => $hasEmail || $hasPhone,
                'status' => $this->intake->fresh()->status
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing intake', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->intake->update(['status' => 'failed']);
            throw $e;
        }
    }
}
