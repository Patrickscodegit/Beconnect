<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExportIntakeToRobawsJob implements ShouldQueue
{
    use Queueable;

    public $intake;

    /**
     * Create a new job instance.
     */
    public function __construct($intakeId)
    {
        $this->intake = is_numeric($intakeId) ? Intake::findOrFail($intakeId) : $intakeId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting Robaws export', ['intake_id' => $this->intake->id]);

        try {
            $exportService = app(RobawsExportService::class);
            
            // Clear previous export errors
            $this->intake->update([
                'last_export_error' => null,
                'last_export_error_at' => null,
            ]);
            
            // Attempt export with clear error handling
            $result = $this->exportWithErrorHandling($exportService);
            
            if ($result['success']) {
                Log::info('Robaws export successful', [
                    'intake_id' => $this->intake->id,
                    'offer_id' => $result['offer_id'] ?? null
                ]);
                
                $this->intake->update([
                    'status' => 'completed',
                    'robaws_offer_id' => $result['offer_id'] ?? null,
                    'robaws_offer_number' => $result['offer_number'] ?? null,
                ]);
            } else {
                $this->handleExportError($result['error']);
            }

        } catch (\Exception $e) {
            Log::error('Exception during Robaws export', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->handleExportError('Unexpected error: ' . $e->getMessage());
        }
    }

    /**
     * Export with clear error handling and client resolution
     */
    private function exportWithErrorHandling(RobawsExportService $service): array
    {
        $extractionData = $this->intake->extraction_data ?? [];
        
        // Check if we have contact info for client resolution
        $contactEmail = data_get($extractionData, 'contact.email') ?: $this->intake->contact_email;
        $contactPhone = data_get($extractionData, 'contact.phone') ?: $this->intake->contact_phone;
        
        if (!$contactEmail && !$contactPhone) {
            return [
                'success' => false,
                'error' => 'Missing contact info (email/phone) required to resolve client in Robaws'
            ];
        }
        
        // Try to resolve client first
        $clientId = $service->resolveClientId($extractionData);
        if (!$clientId) {
            return [
                'success' => false,
                'error' => 'Could not resolve client in Robaws. Contact may not exist or multiple matches found.'
            ];
        }
        
        // Proceed with export
        return $service->exportIntake($this->intake);
    }

    /**
     * Handle export error with clear status and message
     */
    private function handleExportError(string $error): void
    {
        Log::warning('Robaws export failed', [
            'intake_id' => $this->intake->id,
            'error' => $error
        ]);
        
        // Determine status based on error type
        $status = 'export_failed';
        if (str_contains(strtolower($error), 'contact') || str_contains(strtolower($error), 'client')) {
            $status = 'needs_contact';
        }
        
        $this->intake->update([
            'status' => $status,
            'last_export_error' => $error,
            'last_export_error_at' => now(),
        ]);
    }
}
