<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Services\IntakeAggregationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateAggregatedOfferJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180; // 3 minutes for aggregation + API calls
    public $tries = 3;

    public function __construct(
        private Intake $intake
    ) {}

    /**
     * Execute the job.
     */
    public function handle(IntakeAggregationService $aggregationService): void
    {
        Log::info('Starting aggregated offer creation', [
            'intake_id' => $this->intake->id,
            'total_documents' => $this->intake->total_documents,
            'is_multi_document' => $this->intake->is_multi_document
        ]);

        // Check if offer already exists
        if ($this->intake->robaws_offer_id) {
            Log::info('Robaws offer already exists, skipping creation', [
                'intake_id' => $this->intake->id,
                'existing_offer_id' => $this->intake->robaws_offer_id
            ]);
            return;
        }

        try {
            // Aggregate extraction data from all documents
            $aggregatedData = $aggregationService->aggregateExtractionData($this->intake);
            
            Log::info('Extraction data aggregated successfully', [
                'intake_id' => $this->intake->id,
                'has_contact' => !empty($aggregatedData['contact']),
                'has_vehicle' => !empty($aggregatedData['vehicle']),
                'has_shipment' => !empty($aggregatedData['shipment']),
                'has_route' => !empty($aggregatedData['route']),
                'has_cargo' => !empty($aggregatedData['cargo']),
                'sources_merged' => count($aggregatedData['metadata']['sources'] ?? []),
                'confidence' => $aggregatedData['metadata']['confidence'] ?? 0
            ]);
            
            // Create single Robaws offer using aggregated data
            $offerId = $aggregationService->createSingleOffer($this->intake);
            
            Log::info('Aggregated Robaws offer created successfully', [
                'intake_id' => $this->intake->id,
                'offer_id' => $offerId,
                'documents_linked' => $this->intake->documents()->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create aggregated Robaws offer', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update intake with error status
            $this->intake->update([
                'robaws_export_status' => 'error',
                'last_export_error' => $e->getMessage(),
                'last_export_error_at' => now()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateAggregatedOfferJob failed permanently', [
            'intake_id' => $this->intake->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update intake status to indicate permanent failure
        $this->intake->update([
            'robaws_export_status' => 'error',
            'last_export_error' => 'Job failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage(),
            'last_export_error_at' => now()
        ]);
    }
}

