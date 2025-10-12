<?php

namespace App\Observers;

use App\Models\Intake;
use App\Models\QuotationRequest;
use App\Notifications\QuotationSubmittedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class IntakeObserver
{
    /**
     * Handle the Intake "updated" event.
     * Auto-create quotation when intake status changes to 'completed'
     */
    public function updated(Intake $intake): void
    {
        // Check if status changed to 'completed' or 'processing_complete' and auto-create is enabled
        if ($intake->isDirty('status') && 
            in_array($intake->status, ['completed', 'processing_complete']) && 
            config('quotation.auto_create_from_intake', true) &&
            !$intake->quotationRequest) { // Only if no quotation exists yet
            
            try {
                $this->createQuotationFromIntake($intake);
            } catch (\Exception $e) {
                Log::error('Failed to auto-create quotation from intake', [
                    'intake_id' => $intake->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create a quotation from intake data
     */
    protected function createQuotationFromIntake(Intake $intake): QuotationRequest
    {
        Log::info('Auto-creating quotation from completed intake', [
            'intake_id' => $intake->id,
        ]);

        // Extract client and contact info
        $clientName = $intake->customer_name ?? null;
        $contactName = $intake->contact_name ?? $intake->customer_name ?? 'Unknown Contact';
        $contactEmail = $intake->contact_email ?? null;
        $contactPhone = $intake->contact_phone ?? null;
        
        $quotationData = [
            'source' => 'intake',
            'requester_type' => 'customer',
            'intake_id' => $intake->id,
            
            // Client fields (company)
            'client_name' => $clientName,
            'client_email' => $contactEmail, // Often same for small businesses
            'client_tel' => $contactPhone,
            
            // Contact fields (person)
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            
            // Service and routing
            'service_type' => $intake->service_type ?? 'RORO_EXPORT',
            'trade_direction' => $this->extractTradeDirection($intake),
            'por' => $intake->extracted_data['por'] ?? null,
            'pol' => $intake->extracted_data['pol'] ?? null,
            'pod' => $intake->extracted_data['pod'] ?? null,
            'fdest' => $intake->extracted_data['fdest'] ?? null,
            'routing' => [
                'por' => $intake->extracted_data['por'] ?? null,
                'pol' => $intake->extracted_data['pol'] ?? null,
                'pod' => $intake->extracted_data['pod'] ?? null,
                'fdest' => $intake->extracted_data['fdest'] ?? null,
            ],
            
            'cargo_description' => $intake->extracted_data['cargo'] ?? 'See intake documents',
            'cargo_details' => [],
            'pricing_currency' => 'EUR',
            'robaws_sync_status' => 'pending',
            'status' => 'pending',
        ];
        
        $quotation = QuotationRequest::create($quotationData);
        
        // Sync intake files to quotation
        $this->syncIntakeFilesToQuotation($intake, $quotation);
        
        Log::info('Quotation auto-created from intake', [
            'intake_id' => $intake->id,
            'quotation_id' => $quotation->id,
            'request_number' => $quotation->request_number,
            'files_synced' => $intake->files->count(),
        ]);
        
        // Notify team about new quotation from intake
        try {
            Notification::route('mail', config('mail.team_address', 'info@belgaco.be'))
                ->notify(new QuotationSubmittedNotification($quotation));
        } catch (\Exception $e) {
            Log::warning('Failed to send quotation submitted notification', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage()
            ]);
        }
        
        return $quotation;
    }

    /**
     * Sync intake files to quotation files
     */
    protected function syncIntakeFilesToQuotation(Intake $intake, QuotationRequest $quotation): void
    {
        foreach ($intake->files as $intakeFile) {
            try {
                // Copy file to quotation directory
                $sourceDisk = $intakeFile->storage_disk ?? 'documents';
                $sourcePath = $intakeFile->storage_path;
                
                // Generate new filename for quotation
                $extension = pathinfo($intakeFile->filename, PATHINFO_EXTENSION);
                $newFilename = time() . '_' . \Illuminate\Support\Str::random(10) . '.' . $extension;
                $destinationPath = 'quotation_files/' . $quotation->id . '/' . $newFilename;
                
                // Copy the file
                $fileContent = Storage::disk($sourceDisk)->get($sourcePath);
                Storage::disk('public')->put($destinationPath, $fileContent);
                
                // Create quotation file record
                $quotation->files()->create([
                    'original_filename' => $intakeFile->original_filename ?? $intakeFile->filename,
                    'filename' => $newFilename,
                    'file_path' => $destinationPath,
                    'file_size' => $intakeFile->file_size,
                    'mime_type' => $intakeFile->mime_type,
                    'file_type' => 'cargo_info', // Intake files are usually cargo-related
                    'uploaded_by' => null, // From intake, not a user
                    'description' => 'Synced from intake #' . $intake->id,
                ]);
                
                Log::info('Synced intake file to quotation', [
                    'intake_id' => $intake->id,
                    'quotation_id' => $quotation->id,
                    'original_file' => $intakeFile->filename,
                    'new_path' => $destinationPath,
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to sync intake file to quotation', [
                    'intake_id' => $intake->id,
                    'quotation_id' => $quotation->id,
                    'file_id' => $intakeFile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Extract trade direction from intake data
     */
    protected function extractTradeDirection(Intake $intake): string
    {
        $serviceType = $intake->service_type ?? '';
        
        if (str_contains($serviceType, 'EXPORT')) {
            return 'export';
        } elseif (str_contains($serviceType, 'IMPORT')) {
            return 'import';
        }
        
        // Default to export
        return 'export';
    }
}
