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

            // Start with existing extraction data
            $payload = (array) ($this->intake->extraction_data ?? []);

            // If we have files, extract data from them
            if (!$files->isEmpty()) {
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
            } else {
                Log::info('No files found for intake - processing as manual intake', [
                    'intake_id' => $this->intake->id
                ]);
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
            ]);

            // V2-ONLY CLIENT RESOLUTION: Run resolver before validation
            $resolver = app(\App\Services\Robaws\ClientResolver::class);

            $hints = [
                'id'    => $this->intake->metadata['robaws_client_id'] ?? null,               // optional override
                'email' => $this->intake->contact_email ?: ($this->intake->metadata['from_email'] ?? null),
                'phone' => $this->intake->contact_phone ?: null,
                'name'  => $this->intake->customer_name  ?: ($this->intake->metadata['from_name'] ?? null),
            ];

            if ($hit = $resolver->resolve($hints)) {
                $this->intake->robaws_client_id = (string)$hit['id'];
                $this->intake->status = 'processed';
                $this->intake->save();
                return;
            }

            // unchanged fallback rule:
            $hasEmail = filter_var($this->intake->contact_email, FILTER_VALIDATE_EMAIL);
            $hasPhone = !empty($this->intake->contact_phone);
            $this->intake->status = ($hasEmail || $hasPhone) ? 'processed' : 'needs_contact';
            $this->intake->save();

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
