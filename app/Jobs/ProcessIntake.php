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
    public $tempPath;
    public $originalName;
    public $mimeType;
    public $fileSize;

    /**
     * Create a new job instance.
     */
    public function __construct(Intake $intake, string $tempPath, string $originalName, string $mimeType, int $fileSize)
    {
        $this->intake = $intake;
        $this->tempPath = $tempPath;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
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
        Log::info('ProcessIntake job started', [
            'intake_id' => $this->intake->id,
            'current_status' => $this->intake->status,
            'job_started_at' => now()
        ]);

        try {
            // Store file from temp path (background operation)
            $this->storeFileFromTempPath();
            
            // Update intake status to processing immediately (minimal operation)
            $this->intake->update([
                'status' => 'processing'
            ]);
            
            // Dispatch orchestrator for coordinated background processing
            // All heavy work (extraction, client creation, offer creation) happens in background
            \App\Jobs\IntakeOrchestratorJob::dispatch($this->intake)->onQueue('default');
            
            Log::info('Intake processed, orchestrator dispatched for background processing', [
                'intake_id' => $this->intake->id,
                'method' => 'orchestrated_background_processing'
            ]);
            
            return;

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
     * Create basic Document record from IntakeFile (fast, no heavy processing)
     */
    private function createBasicDocumentFromFile(\App\Models\IntakeFile $file): void
    {
        Log::info('Creating basic document from IntakeFile', [
            'intake_id' => $this->intake->id,
            'file_id' => $file->id,
            'filename' => $file->filename
        ]);

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

        \App\Models\Document::create([
            'intake_id' => $this->intake->id,
            'filename' => $file->filename,
            'file_path' => $file->storage_path,
            'mime_type' => $file->mime_type,
            'file_size' => $file->file_size,
            'storage_disk' => $file->storage_disk,
            'original_filename' => $file->original_filename,
            'processing_status' => 'pending',
            'status' => 'pending',
        ]);

        Log::info('Basic document created from IntakeFile', [
            'intake_id' => $this->intake->id,
            'file_id' => $file->id,
            'filename' => $file->filename
        ]);
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

            // Process document for Robaws integration (fast, no API calls)
            try {
                $integrationService = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
                $success = $integrationService->processDocument($document, $extractionData);
                
                Log::info('Document processed for Robaws integration (fast)', [
                    'document_id' => $document->id,
                    'intake_id' => $this->intake->id,
                    'success' => $success
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to process document for Robaws integration', [
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
    
    /**
     * Store file from temporary path (background operation)
     */
    private function storeFileFromTempPath(): void
    {
        try {
            $disk = 'documents';
            $dir = '';
            $ext = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION) ?? '');
            $name = (string) \Illuminate\Support\Str::uuid() . ($ext ? ".$ext" : '');
            $storagePath = $dir . '/' . $name;
            
            // Copy file from temp path to storage
            \Illuminate\Support\Facades\Storage::disk($disk)->put($storagePath, file_get_contents($this->tempPath));
            
            // Create IntakeFile record
            \App\Models\IntakeFile::create([
                'intake_id' => $this->intake->id,
                'filename' => $this->originalName,
                'storage_path' => $storagePath,
                'storage_disk' => $disk,
                'mime_type' => $this->mimeType,
                'file_size' => $this->fileSize,
            ]);
            
            Log::info('File stored from temp path', [
                'intake_id' => $this->intake->id,
                'filename' => $this->originalName,
                'storage_path' => $storagePath
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to store file from temp path', [
                'intake_id' => $this->intake->id,
                'filename' => $this->originalName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
}
