<?php

namespace App\Observers;

use App\Models\QuotationRequest;
use App\Services\Pricing\VatResolverInterface;
use App\Services\Pricing\QuotationVatService;
use Illuminate\Support\Facades\Log;

class QuotationRequestObserver
{
    public function __construct(
        private readonly VatResolverInterface $vatResolver,
        private readonly QuotationVatService $quotationVatService,
    ) {}

    /**
     * Set project_vat_code before saving
     */
    public function saving(QuotationRequest $quotationRequest): void
    {
        try {
            $projectVatCode = $this->vatResolver->determineProjectVatCode($quotationRequest);
            $quotationRequest->project_vat_code = $projectVatCode;
            
            Log::debug('QuotationRequestObserver::saving - Set project_vat_code', [
                'quotation_id' => $quotationRequest->id,
                'pol' => $quotationRequest->pol,
                'pod' => $quotationRequest->pod,
                'project_vat_code' => $projectVatCode,
            ]);
        } catch (\Exception $e) {
            Log::error('QuotationRequestObserver::saving - Error setting VAT code', [
                'quotation_id' => $quotationRequest->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Fallback to default
            $quotationRequest->project_vat_code = '21% VF';
        }
    }

    /**
     * Recalculate VAT for quotation and all articles after save
     */
    public function saved(QuotationRequest $quotationRequest): void
    {
        // Recalculate if relevant fields changed OR if project_vat_code changed
        $relevantFieldsChanged = $quotationRequest->wasChanged(['pol', 'pod', 'robaws_client_id', 'project_vat_code']);
        
        if ($relevantFieldsChanged) {
            try {
                Log::debug('QuotationRequestObserver::saved - Recalculating VAT', [
                    'quotation_id' => $quotationRequest->id,
                    'pol' => $quotationRequest->pol,
                    'pod' => $quotationRequest->pod,
                    'project_vat_code' => $quotationRequest->project_vat_code,
                    'changed_fields' => $quotationRequest->getChanges(),
                ]);
                
                $this->quotationVatService->recalculateVatForQuotation($quotationRequest);
            } catch (\Exception $e) {
                Log::error('QuotationRequestObserver::saved - Error recalculating VAT', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Handle the QuotationRequest "updated" event.
     * Sync status back to linked intake when quotation status changes
     */
    public function updated(QuotationRequest $quotationRequest): void
    {
        // Only proceed if status changed and there's a linked intake
        if ($quotationRequest->isDirty('status') && $quotationRequest->intake_id) {
            $this->syncStatusToIntake($quotationRequest);
        }
    }

    /**
     * Sync quotation status back to intake
     */
    protected function syncStatusToIntake(QuotationRequest $quotationRequest): void
    {
        $intake = $quotationRequest->intake;
        
        if (!$intake) {
            return;
        }

        // Map quotation status to intake status
        $intakeStatus = match ($quotationRequest->status) {
            'pending' => 'processing',      // Quotation pending → Intake still processing
            'processing' => 'processing',   // Quotation being worked on → Intake processing
            'quoted' => 'completed',        // Quotation sent → Intake completed
            'accepted' => 'completed',      // Quotation accepted → Intake completed
            'rejected' => 'completed',      // Quotation rejected → Still completed (we tried)
            'expired' => 'completed',       // Quotation expired → Intake completed
            default => null,
        };

        if ($intakeStatus && $intake->status !== $intakeStatus) {
            $oldStatus = $intake->status;
            
            // Prevent observer recursion
            $intake->withoutEvents(function () use ($intake, $intakeStatus) {
                $intake->update(['status' => $intakeStatus]);
            });
            
            Log::info('Synced quotation status to intake', [
                'quotation_id' => $quotationRequest->id,
                'quotation_request_number' => $quotationRequest->request_number,
                'quotation_status' => $quotationRequest->status,
                'intake_id' => $intake->id,
                'intake_status_old' => $oldStatus,
                'intake_status_new' => $intakeStatus,
            ]);
        }
    }
}
