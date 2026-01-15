<?php

namespace App\Services\Quotation;

use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequest;
use App\Services\CarrierRules\CarrierRuleIntegrationService;
use Illuminate\Support\Facades\Log;

class QuotationPricingOrchestrator
{
    public function __construct(
        private CarrierRuleIntegrationService $carrierRuleIntegration
    ) {}

    public function recalculateForCommodityItem(QuotationCommodityItem $item): void
    {
        $quotation = $item->quotationRequest;
        if (!$quotation) {
            return;
        }

        try {
            $this->carrierRuleIntegration->processCommodityItem($item);
        } catch (\Exception $e) {
            Log::error('QuotationPricingOrchestrator: carrier rule processing failed', [
                'item_id' => $item->id,
                'quotation_request_id' => $item->quotation_request_id,
                'error' => $e->getMessage(),
            ]);
        }

        QuotationCommodityItem::recalculateQuotationArticles($quotation->id, $item->id);
        $this->recalculateTotalsOnly($quotation);
    }

    public function recalculateForScheduleChange(QuotationRequest $quotation): void
    {
        $quotation = $quotation->fresh(['commodityItems', 'selectedSchedule.carrier']);
        if (!$quotation || $quotation->commodityItems->isEmpty()) {
            return;
        }

        foreach ($quotation->commodityItems as $item) {
            try {
                $this->carrierRuleIntegration->processCommodityItem($item);
            } catch (\Exception $e) {
                Log::error('QuotationPricingOrchestrator: schedule-change carrier rule failed', [
                    'item_id' => $item->id,
                    'quotation_request_id' => $quotation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        QuotationCommodityItem::recalculateQuotationArticles($quotation->id);
        $this->recalculateTotalsOnly($quotation);
    }

    public function recalculateForQuotationId(int $quotationRequestId, ?int $triggeringItemId = null): void
    {
        if ($triggeringItemId) {
            $item = QuotationCommodityItem::find($triggeringItemId);
            if ($item) {
                $this->recalculateForCommodityItem($item);
                return;
            }
        }

        $quotation = QuotationRequest::find($quotationRequestId);
        if (!$quotation) {
            return;
        }

        QuotationCommodityItem::recalculateQuotationArticles($quotation->id, $triggeringItemId);
        $this->recalculateTotalsOnly($quotation);
    }

    public function recalculateTotalsOnly(QuotationRequest $quotation): void
    {
        $quotation->calculateTotals();
        $quotation->saveQuietly();
    }
}
