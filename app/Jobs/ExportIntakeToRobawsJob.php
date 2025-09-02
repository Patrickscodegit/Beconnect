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
        
        // Check if we already have a resolved client ID from ProcessIntake
        if (!empty($this->intake->robaws_client_id)) {
            Log::info('Using pre-resolved client ID from ProcessIntake', [
                'intake_id' => $this->intake->id,
                'robaws_client_id' => $this->intake->robaws_client_id
            ]);
            
            // Client already resolved - proceed directly to export
            return $service->exportIntake($this->intake);
        }
        
        // Fallback: Try to resolve client now (for legacy intakes)
        $clientId = $service->resolveClientId($extractionData);
        if (!$clientId) {
            return [
                'success' => false,
                'error' => 'Could not resolve client in Robaws. Please ensure customer name, email, or phone is valid.'
            ];
        }
        
        // Store resolved client ID and proceed with export
        $this->intake->robaws_client_id = $clientId;
        $this->intake->save();
        
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
