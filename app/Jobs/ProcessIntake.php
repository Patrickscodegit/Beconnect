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
use Illuminate\Support\Facades\DB;

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

            // Merge contact data: prefer extracted values over empty seeded values
            $extractedContact = (array) data_get($payload, 'contact', []);
            $intakeContactSeed = array_filter([
                'name' => $this->intake->customer_name,
                'email' => $this->intake->contact_email,
                'phone' => $this->intake->contact_phone,
            ], fn ($v) => $v !== null && $v !== '');

            $contactData = $this->preferNonEmpty($extractedContact, $intakeContactSeed);

            // Optional: infer company from email domain if missing
            if (!isset($contactData['company']) && !empty($contactData['email'])) {
                $contactData['company'] = $this->inferCompanyFromEmail($contactData['email']);
            }

            // Update payload with merged contact
            $payload['contact'] = array_filter($contactData);

            // Persist both JSON and columns in one transaction
            \DB::transaction(function () use ($payload, $contactData) {
                $this->intake->extraction_data = $payload;

                // Hydrate flat columns only if empty
                $this->intake->customer_name = $this->intake->customer_name ?: data_get($contactData, 'name');
                $this->intake->contact_email = $this->intake->contact_email ?: data_get($contactData, 'email');
                $this->intake->contact_phone = $this->intake->contact_phone ?: data_get($contactData, 'phone');

                // Determine status based on final hydrated columns
                $hasEmail = filter_var($this->intake->contact_email, FILTER_VALIDATE_EMAIL);
                $hasPhone = !empty($this->intake->contact_phone);

                if ($hasEmail || $hasPhone) {
                    $this->intake->status = 'processed';
                    $this->intake->last_export_error = null;
                } else {
                    $this->intake->status = 'needs_contact';
                    $this->intake->last_export_error = 'Missing contact information - either email or phone number is required';
                }

                $this->intake->save();

                // Authoritative post-merge log
                Log::info('Post-merge contact status', [
                    'intake_id' => $this->intake->id,
                    'extracted_contact' => data_get($payload, 'contact'),
                    'columns' => [
                        'name' => $this->intake->customer_name,
                        'email' => $this->intake->contact_email,
                        'phone' => $this->intake->contact_phone,
                    ],
                    'final_status' => $this->intake->status,
                ]);
            });

            // Export trigger: only after the merged result
            if ($this->intake->status === 'processed') {
                Log::info('Contact info sufficient - dispatching export', [
                    'intake_id' => $this->intake->id,
                    'contact_email' => $this->intake->contact_email,
                    'contact_phone' => $this->intake->contact_phone,
                ]);
                
                // Dispatch export job
                dispatch(new \App\Jobs\ExportIntakeToRobawsJob($this->intake->id));
            }

            Log::info('Intake processing completed', [
                'intake_id' => $this->intake->id,
                'files_processed' => $files->count(),
                'ready_for_export' => $this->intake->status === 'processed',
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

    /**
     * Prefer non-empty values from incoming over base
     */
    private function preferNonEmpty(array $base, array $incoming): array
    {
        foreach ($incoming as $k => $v) {
            if (is_array($v)) {
                $base[$k] = $this->preferNonEmpty($base[$k] ?? [], $v);
            } else {
                // only take incoming if it is non-empty
                if ($v !== null && $v !== '') {
                    $base[$k] = $v;
                }
            }
        }
        return $base;
    }

    /**
     * Infer company name from email domain
     */
    private function inferCompanyFromEmail(string $email): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        $domain = explode('@', $email)[1] ?? '';
        $domainParts = explode('.', $domain);
        $mainDomain = $domainParts[0] ?? '';
        
        // Skip common personal email providers
        $personalProviders = ['gmail', 'yahoo', 'hotmail', 'outlook', 'live', 'icloud', 'aol'];
        if (in_array(strtolower($mainDomain), $personalProviders)) {
            return null;
        }
        
        // Clean and format company name
        return ucfirst(strtolower($mainDomain));
    }
}
