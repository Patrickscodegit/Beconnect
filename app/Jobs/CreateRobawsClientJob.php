<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateRobawsClientJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes for client creation
    public $tries = 3;
    public $backoff = [30, 60, 120]; // Progressive backoff

    public function __construct(
        public int $intakeId,
        public array $contactData
    ) {
        $this->onQueue('high'); // High priority for client creation
    }

    /**
     * Execute the job.
     */
    public function handle(RobawsApiClient $robawsClient): void
    {
        Log::info('Starting background client creation', [
            'intake_id' => $this->intakeId,
            'contact_data_keys' => array_keys($this->contactData)
        ]);

        try {
            $intake = Intake::find($this->intakeId);
            if (!$intake) {
                Log::warning('Intake not found for client creation', [
                    'intake_id' => $this->intakeId
                ]);
                return;
            }

            // Check if client already exists and status is already updated
            if ($intake->robaws_client_id && $intake->status !== 'client_pending') {
                Log::info('Client already exists and status updated, skipping creation', [
                    'intake_id' => $this->intakeId,
                    'existing_client_id' => $intake->robaws_client_id,
                    'current_status' => $intake->status
                ]);
                return;
            }

            // Check if client already exists but status needs updating
            if ($intake->robaws_client_id && $intake->status === 'client_pending') {
                Log::info('Client already exists, updating status only', [
                    'intake_id' => $this->intakeId,
                    'existing_client_id' => $intake->robaws_client_id
                ]);
                
                // Update status to export_queued
                $updateResult = $intake->update(['status' => 'export_queued']);
                
                if (!$updateResult) {
                    Log::error('Failed to update intake status from client_pending to export_queued', [
                        'intake_id' => $this->intakeId,
                        'client_id' => $intake->robaws_client_id
                    ]);
                } else {
                    Log::info('Successfully updated intake status to export_queued', [
                        'intake_id' => $this->intakeId,
                        'client_id' => $intake->robaws_client_id
                    ]);
                }
                return;
            }

            // Get fresh contact data from intake (in case extraction updated it)
            $freshContactData = [
                'name' => $intake->customer_name,
                'email' => $intake->contact_email,
                'phone' => $intake->contact_phone,
            ];
            
            Log::info('Using fresh contact data for client creation', [
                'intake_id' => $this->intakeId,
                'fresh_contact_data' => $freshContactData
            ]);
            
            // Prepare hints for client resolution
            $hints = [
                'client_name'   => $freshContactData['name'] ?? 'Unknown Client',
                'email'         => $freshContactData['email'] ?? null,
                'phone'         => $freshContactData['phone'] ?? null,
                'contact_email' => $freshContactData['email'] ?? null,
                'contact_phone' => $freshContactData['phone'] ?? null,
                'is_primary'    => true,
                'receives_quotes' => true,
                'language'      => 'en',
                'currency'      => 'EUR',
            ];

            // Create or resolve client
            $result = $robawsClient->resolveOrCreateClientAndContact($hints);
            
            if (!isset($result['id'])) {
                throw new \RuntimeException('Robaws client creation failed: missing id');
            }

            $clientId = $result['id'];
            
            // Update intake with client ID and status
            $updateResult = $intake->update([
                'robaws_client_id' => (string) $clientId,
                'status' => 'export_queued'
            ]);

            if (!$updateResult) {
                Log::error('Failed to update intake status after client creation', [
                    'intake_id' => $this->intakeId,
                    'client_id' => $clientId
                ]);
                throw new \RuntimeException('Failed to update intake status');
            }

            Log::info('Background client creation completed successfully', [
                'intake_id' => $this->intakeId,
                'client_id' => $clientId,
                'created' => $result['created'] ?? false,
                'source' => $result['source'] ?? 'unknown',
                'status_updated' => true
            ]);

        } catch (\Exception $e) {
            Log::error('Background client creation failed', [
                'intake_id' => $this->intakeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update intake status to indicate client creation failed
            $intake = Intake::find($this->intakeId);
            if ($intake) {
                $intake->update([
                    'status' => 'client_creation_failed',
                    'last_export_error' => 'Client creation failed: ' . $e->getMessage(),
                    'last_export_error_at' => now()
                ]);
            }

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateRobawsClientJob failed permanently', [
            'intake_id' => $this->intakeId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update intake status to indicate permanent failure
        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'client_creation_failed',
                'last_export_error' => 'Client creation failed permanently: ' . $exception->getMessage(),
                'last_export_error_at' => now()
            ]);
        }
    }
}