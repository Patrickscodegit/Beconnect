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

            // Try to find or create Robaws client
            $robawsClientId = $this->findOrCreateRobawsClient($contactData);
            
            if ($robawsClientId) {
                $this->intake->update([
                    'robaws_client_id' => (string)$robawsClientId,
                    'status' => 'completed'
                ]);
                
                // Automatically dispatch export job when client is resolved
                \App\Jobs\ExportIntakeToRobawsJob::dispatch($this->intake->id);
                
                Log::info('Intake processed with client and export job dispatched', [
                    'intake_id' => $this->intake->id,
                    'client_id' => $robawsClientId,
                    'method' => 'auto_resolve_or_create'
                ]);
                
                return;
            }

            // If client resolution/creation failed, still mark as processed
            // Contact info is no longer mandatory for processing
            $this->intake->update(['status' => 'processed']);
            
            // Dispatch export job even without client (will attempt client creation during export)
            \App\Jobs\ExportIntakeToRobawsJob::dispatch($this->intake->id);
            
            Log::info('Intake processed without client - export will attempt client creation', [
                'intake_id' => $this->intake->id,
            ]);

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

    /**
     * Find existing client or create a new one with available data
     */
    private function findOrCreateRobawsClient(array $contactData): ?string
    {
        try {
            $resolver = app(\App\Services\Robaws\ClientResolver::class);

            $hints = [
                'id'    => $this->intake->metadata['robaws_client_id'] ?? null,
                'email' => $contactData['email'] ?? ($this->intake->metadata['from_email'] ?? null),
                'phone' => $contactData['phone'] ?? null,
                'name'  => $contactData['name'] ?? ($this->intake->metadata['from_name'] ?? null),
            ];

            // Try to find existing client first
            if ($hit = $resolver->resolve($hints)) {
                Log::info('Found existing Robaws client', [
                    'intake_id' => $this->intake->id,
                    'client_id' => $hit['id'],
                    'confidence' => $hit['confidence'] ?? null,
                ]);
                
                return (string)$hit['id'];
            }

            // If no client found, create a new one with whatever info we have
            $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
            
            $clientId = $this->createRobawsClient($apiClient, $contactData);
            
            if ($clientId) {
                Log::info('Created new Robaws client', [
                    'intake_id' => $this->intake->id,
                    'client_id' => $clientId,
                    'data_used' => array_filter($contactData)
                ]);
                
                return (string)$clientId;
            }

            Log::warning('Failed to find or create Robaws client', [
                'intake_id' => $this->intake->id,
                'contact_data' => $contactData
            ]);

            return null;
            
        } catch (\Exception $e) {
            Log::error('Error in findOrCreateRobawsClient', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage()
            ]);
            
            // Don't fail the entire process if client creation fails
            return null;
        }
    }

    /**
     * Create a new client in Robaws using available contact data
     */
    private function createRobawsClient(\App\Services\Export\Clients\RobawsApiClient $apiClient, array $contactData): ?int
    {
        // Build client data with fallbacks
        $clientPayload = [
            'name' => $contactData['name'] ?? $this->intake->customer_name ?? 'Customer #' . $this->intake->id,
            'email' => $contactData['email'] ?? $this->intake->contact_email ?? 'noreply@bconnect.com',
        ];

        // Add optional fields if available
        if (!empty($contactData['phone']) || !empty($this->intake->contact_phone)) {
            $clientPayload['phone'] = $contactData['phone'] ?? $this->intake->contact_phone;
        }

        try {
            // Use the direct Robaws API to create a client
            $response = $apiClient->createClient($clientPayload);
            
            if ($response && isset($response['id'])) {
                return (int) $response['id'];
            }
            
            Log::error('Failed to create client - no ID in response', [
                'intake_id' => $this->intake->id,
                'payload' => $clientPayload,
                'response' => $response
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Exception creating Robaws client', [
                'intake_id' => $this->intake->id,
                'payload' => $clientPayload,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}
