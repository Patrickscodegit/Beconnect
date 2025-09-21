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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * Prevent overlapping jobs per intake
     */
    public function middleware(): array
    {
        return [ (new WithoutOverlapping('intake:'.$this->intake->id))->dontRelease() ];
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

            // If we have files, extract data from them and create documents
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
                        
                        // Create Document record from IntakeFile
                        $this->createDocumentFromFile($file, $fileData);
                    }
                }
            } else {
                Log::info('No files found for intake - processing as manual intake', [
                    'intake_id' => $this->intake->id
                ]);
            }

            // Merge contact data: seeded data takes precedence, extracted fills gaps
            $contactData = $this->normalizeContact($payload);
            $payload['contact'] = $contactData;
            
            // Check if we have sufficient identity information for images
            $hasIdentity = $this->hasIdentityInformation($payload, $files);
            
            $this->intake->update([
                'extraction_data' => $payload,
                'customer_name'   => $contactData['name']  ?? $this->intake->customer_name,
                'contact_email'   => $contactData['email'] ?? $this->intake->contact_email,
                'contact_phone'   => $contactData['phone'] ?? $this->intake->contact_phone,
            ]);

            // Try to find or create Robaws client
            $robawsClientId = $this->findOrCreateRobawsClient($contactData);
            
            if ($robawsClientId) {
                $this->intake->update([
                    'robaws_client_id' => (string) $robawsClientId,
                    'status'           => 'export_queued',
                ]);
                
                // Export job removed - individual document processing now handles offer creation
                
                Log::info('Intake processed and export queued', [
                    'intake_id' => $this->intake->id,
                    'client_id' => $robawsClientId,
                    'method'    => 'auto_resolve_or_create'
                ]);
                
                return;
            }

            // Determine final status based on available information
            $finalStatus = 'export_queued';
            $flags = [];
            
            if (!$hasIdentity) {
                // For images, don't block - flag for review instead
                if ($this->hasImageFiles($files)) {
                    $flags[] = 'needs_review';
                    Log::info('Image intake flagged for review due to missing identity', [
                        'intake_id' => $this->intake->id
                    ]);
                }
            }

            // Update intake with final status and flags
            $this->intake->update([
                'status' => $finalStatus,
                'flags' => $flags
            ]);
            
            // Export job removed - individual document processing now handles offer creation
            
            Log::info('Intake processed without client - export will attempt client creation', [
                'intake_id' => $this->intake->id,
                'contact_keys_present' => array_keys($contactData),
            ]);

            Log::info('Intake processing completed', [
                'intake_id' => $this->intake->id,
                'files_processed' => $files->count(),
                'ready_for_export' => !empty($contactData['email']) || !empty($contactData['phone']),
                'status' => $this->intake->fresh()->status
            ]);
            
            // Dispatch file upload job after all documents are processed
            DB::afterCommit(function () {
                \App\Jobs\ExportIntakeToRobawsJob::dispatch($this->intake->id);
            });

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
     * Normalize contact data with proper name splitting and fallbacks
     */
    private function normalizeContact(array $payload): array
    {
        $c = (array) data_get($payload, 'contact', []);

        // split "John Smith" if first/last missing
        if (!empty($c['name']) && (empty($c['first_name']) || empty($c['last_name']))) {
            [$first, $last] = array_pad(preg_split('/\s+/', trim($c['name']), 2), 2, null);
            $c['first_name'] = $c['first_name'] ?? $first;
            $c['last_name']  = $c['last_name']  ?? $last;
        }

        // fallbacks from flat intake cols / metadata if extractor is sparse
        $c['email'] = $c['email'] ?? $this->intake->contact_email ?? data_get($this->intake, 'metadata.from_email');
        $c['phone'] = $c['phone'] ?? $this->intake->contact_phone;
        $c['name']  = $c['name']  ?? $this->intake->customer_name ?? data_get($this->intake, 'metadata.from_name');

        // clean empties
        return array_filter($c, fn($v) => $v !== null && $v !== '');
    }

    /**
     * Find existing client or create a new one with available data
     */
    private function findOrCreateRobawsClient(array $contactData): ?int
    {
        try {
            $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
            
            // Prepare hints for the robust resolution
            $hints = [
                'client_name'   => $contactData['company'] ?? $contactData['name'] ?? $this->intake->customer_name,
                'email'         => $contactData['email'] ?? data_get($this->intake, 'metadata.from_email'),
                'phone'         => $contactData['phone'] ?? null,
                'first_name'    => $contactData['first_name'] ?? null,
                'last_name'     => $contactData['last_name']  ?? $contactData['surname'] ?? null,
                'contact_email' => $contactData['email'] ?? null,
                'contact_phone' => $contactData['phone'] ?? null,
                'function'      => $contactData['function'] ?? null,
                'is_primary'    => true,
                'receives_quotes' => true,
                'language'      => 'en',
                'currency'      => 'EUR',
            ];
            
            // Use the robust resolution method that prevents duplicate client creation
            $resolved = $apiClient->resolveOrCreateClientAndContact($hints);
            
            Log::info('Client resolution completed', [
                'intake_id' => $this->intake->id,
                'client_id' => $resolved['id'],
                'created' => $resolved['created'],
                'source' => $resolved['source'],
            ]);
            
            return (int)$resolved['id'];
            
        } catch (\Exception $e) {
            Log::error('Failed to find or create Robaws client', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'contact_data' => $contactData
            ]);
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
            'name' => $contactData['company'] ?? $contactData['name'] ?? $this->intake->customer_name ?? 'Customer #' . $this->intake->id,
            'email' => $contactData['email'] ?? $this->intake->contact_email ?? 'noreply@bconnect.com',
        ];

        // Add optional fields if available  
        if (!empty($contactData['phone']) || !empty($this->intake->contact_phone)) {
            $clientPayload['tel'] = $contactData['phone'] ?? $this->intake->contact_phone; // Use 'tel' not 'phone' for Robaws
        }

        // Add contact person if we have contact data
        if (!empty($contactData['first_name']) || !empty($contactData['last_name']) || !empty($contactData['email']) || !empty($contactData['phone'])) {
            $clientPayload['contact_person'] = [
                'first_name' => $contactData['first_name'] ?? null,
                'last_name' => $contactData['last_name'] ?? $contactData['surname'] ?? null,
                'email' => $contactData['email'] ?? $this->intake->contact_email ?? null,
                'tel' => $contactData['phone'] ?? $this->intake->contact_phone ?? null, // Use 'tel' not 'phone' for Robaws
                'is_primary' => true,
                'receives_quotes' => true,
            ];
            
            // Filter out empty values
            $clientPayload['contact_person'] = array_filter($clientPayload['contact_person'], function($value) {
                return $value !== null && $value !== '';
            });
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

    /**
     * Check if we have sufficient identity information to proceed
     */
    private function hasIdentityInformation(array $payload, $files): bool
    {
        $contactData = $payload['contact'] ?? [];
        
        // Traditional contact info
        if (!empty($contactData['email']) || !empty($contactData['name']) || !empty($contactData['company'])) {
            return true;
        }
        
        // VIN candidates from OCR
        foreach ($payload as $fileData) {
            if (is_array($fileData) && !empty($fileData['vin_candidates'])) {
                return true;
            }
        }
        
        // Check raw VIN candidates at top level
        if (!empty($payload['vin_candidates'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if the intake has image files
     */
    private function hasImageFiles($files): bool
    {
        foreach ($files as $file) {
            if (str_starts_with($file->mime_type, 'image/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create Document record from IntakeFile
     */
    private function createDocumentFromFile($file, array $extractionData): void
    {
        try {
            // Check if document already exists for this file
            $existingDoc = \App\Models\Document::where('intake_id', $this->intake->id)
                ->where('filename', $file->filename)
                ->first();
                
            if ($existingDoc) {
                Log::info('Document already exists for file', [
                    'intake_id' => $this->intake->id,
                    'file_id' => $file->id,
                    'document_id' => $existingDoc->id
                ]);
                return;
            }

            // Create document record
            $document = \App\Models\Document::create([
                'intake_id' => $this->intake->id,
                'filename' => $file->filename,
                'file_path' => $file->storage_path,
                'mime_type' => $file->mime_type,
                'file_size' => $file->file_size,
                'original_filename' => $file->original_filename,
                'storage_disk' => $file->storage_disk, // Use the same disk as IntakeFile
                'document_type' => $this->getDocumentType($file->mime_type),
                'extraction_data' => json_encode($extractionData),
                'extraction_status' => 'completed',
                'extraction_service' => 'ExtractionService',
                'extracted_at' => now(),
                'processing_status' => 'processed',
                'status' => 'ready'
            ]);

            Log::info('Document created from IntakeFile', [
                'intake_id' => $this->intake->id,
                'file_id' => $file->id,
                'document_id' => $document->id,
                'filename' => $file->filename
            ]);

            // Dispatch Robaws integration for the created document
            try {
                $integrationService = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
                $success = $integrationService->processDocument($document, $extractionData);
                
                Log::info('Robaws integration dispatched for IntakeFile document', [
                    'document_id' => $document->id,
                    'intake_id' => $this->intake->id,
                    'success' => $success
                ]);
                
                // If processing was successful, create the Robaws offer (only if not already created)
                if ($success && !$document->robaws_quotation_id) {
                    $offerResult = $integrationService->createOfferFromDocument($document);
                    if ($offerResult) {
                        Log::info('Robaws offer created for IntakeFile document', [
                            'document_id' => $document->id,
                            'offer_id' => $offerResult['id'] ?? 'unknown'
                        ]);
                    }
                } elseif ($document->robaws_quotation_id) {
                    Log::info('Robaws offer already exists for IntakeFile document, skipping creation', [
                        'document_id' => $document->id,
                        'existing_offer_id' => $document->robaws_quotation_id
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to dispatch Robaws integration for IntakeFile document', [
                    'document_id' => $document->id,
                    'intake_id' => $this->intake->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to create document from IntakeFile', [
                'intake_id' => $this->intake->id,
                'file_id' => $file->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Determine document type from mime type
     */
    private function getDocumentType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        
        if ($mimeType === 'message/rfc822') {
            return 'email';
        }
        
        if (str_starts_with($mimeType, 'application/pdf')) {
            return 'pdf';
        }
        
        return 'document';
    }
}
