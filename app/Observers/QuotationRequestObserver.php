<?php

namespace App\Observers;

use App\Models\QuotationRequest;
use Illuminate\Support\Facades\Log;

class QuotationRequestObserver
{
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
