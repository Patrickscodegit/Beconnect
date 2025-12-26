<?php

namespace App\Services\CarrierRules;

use App\Models\Port;
use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use Illuminate\Support\Facades\Log;

/**
 * Service to integrate CarrierRuleEngine into quotation flow
 */
class CarrierRuleIntegrationService
{
    public function __construct(
        private CarrierRuleEngine $engine
    ) {}

    /**
     * Process commodity item through carrier rules and apply results
     */
    public function processCommodityItem(QuotationCommodityItem $item): void
    {
        // Get quotation request with schedule
        $quotation = $item->quotationRequest;
        if (!$quotation) {
            return;
        }

        // Load schedule with carrier and ports
        $schedule = $quotation->selectedSchedule;
        if (!$schedule || !$schedule->carrier_id) {
            // No carrier context, skip rule processing
            return;
        }

        // Load POD port
        $schedule->load('podPort');
        $podPort = $schedule->podPort;
        if (!$podPort) {
            Log::warning('CarrierRuleIntegration: Schedule has no POD port', [
                'schedule_id' => $schedule->id,
                'quotation_id' => $quotation->id,
            ]);
            return;
        }

        // Create input DTO
        $input = CargoInputDTO::fromCommodityItem($item, $podPort, $schedule);
        
        // Override carrier ID and POD port ID from schedule
        $input->carrierId = $schedule->carrier_id;
        $input->podPortId = $schedule->pod_id ?? $podPort->id;

        // Process through engine
        try {
            $result = $this->engine->processCargo($input);

            // Store chargeable LM and meta
            $item->chargeable_lm = $result->chargeableMeasure->chargeableLm;
            $item->carrier_rule_meta = [
                'classified_category' => $result->classifiedVehicleCategory,
                'matched_category_group' => $result->matchedCategoryGroup,
                'acceptance_status' => $result->acceptanceStatus,
                'violations' => $result->violations,
                'approvals_required' => $result->approvalsRequired,
                'base_lm' => $result->chargeableMeasure->baseLm,
                'chargeable_lm' => $result->chargeableMeasure->chargeableLm,
                'transform_reason' => $result->chargeableMeasure->meta['transform_reason'] ?? null,
                'applied_transform_rule_id' => $result->chargeableMeasure->appliedTransformRuleId,
                'surcharge_events' => $result->surchargeEvents,
            ];

            // Save item with rule results (use saveQuietly to avoid triggering saved event again)
            $item->saveQuietly();

            // Auto-add surcharge articles from quoteLineDrafts
            $this->addSurchargeArticles($quotation, $result->quoteLineDrafts, $item);

            Log::info('CarrierRuleIntegration: Processed commodity item', [
                'item_id' => $item->id,
                'carrier_id' => $input->carrierId,
                'pod_port_id' => $input->podPortId,
                'acceptance_status' => $result->acceptanceStatus,
                'chargeable_lm' => $result->chargeableMeasure->chargeableLm,
                'surcharge_events_count' => count($result->surchargeEvents),
                'articles_added_count' => count($result->quoteLineDrafts),
            ]);
        } catch (\Exception $e) {
            Log::error('CarrierRuleIntegration: Error processing commodity item', [
                'item_id' => $item->id,
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Add surcharge articles from quoteLineDrafts
     */
    private function addSurchargeArticles(
        QuotationRequest $quotation,
        array $quoteLineDrafts,
        QuotationCommodityItem $item
    ): void {
        foreach ($quoteLineDrafts as $draft) {
            // Check if article already exists for this quotation
            $existingArticle = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                ->where('article_cache_id', $draft['article_id'])
                ->whereJsonContains('notes', $draft['meta']['event_code'] ?? '')
                ->first();

            if ($existingArticle) {
                // Update quantity if needed
                if ($draft['qty'] > 0) {
                    $existingArticle->quantity = $draft['qty'];
                    $existingArticle->save();
                }
                continue;
            }

            // Get article
            $article = RobawsArticleCache::find($draft['article_id']);
            if (!$article) {
                Log::warning('CarrierRuleIntegration: Article not found', [
                    'article_id' => $draft['article_id'],
                ]);
                continue;
            }

            // Calculate selling price
            $sellingPrice = null;
            try {
                if ($quotation->pricing_tier_id && $quotation->pricingTier) {
                    $sellingPrice = $article->getPriceForTier($quotation->pricingTier);
                }
            } catch (\Exception $e) {
                // Fall through to role-based pricing
            }

            if ($sellingPrice === null) {
                $role = $quotation->customer_role ?? 'default';
                $sellingPrice = $article->getPriceForRole($role);
            }

            // Use amount override if provided, otherwise use selling price
            $unitPrice = $draft['amount_override'] ?? $sellingPrice ?? $article->unit_price ?? 0;

            // Create article
            QuotationRequestArticle::create([
                'quotation_request_id' => $quotation->id,
                'article_cache_id' => $draft['article_id'],
                'item_type' => 'standalone',
                'quantity' => $draft['qty'],
                'unit_type' => $article->unit_type ?? 'unit',
                'unit_price' => $article->unit_price ?? 0,
                'selling_price' => $unitPrice,
                'subtotal' => $unitPrice * $draft['qty'],
                'currency' => $article->currency ?? 'EUR',
                'notes' => json_encode([
                    'carrier_rule_applied' => true,
                    'event_code' => $draft['meta']['event_code'] ?? null,
                    'reason' => $draft['meta']['reason'] ?? null,
                    'matched_rule_id' => $draft['meta']['matched_rule_id'] ?? null,
                    'commodity_item_id' => $item->id,
                ]),
            ]);

            Log::info('CarrierRuleIntegration: Added surcharge article', [
                'quotation_id' => $quotation->id,
                'article_id' => $draft['article_id'],
                'event_code' => $draft['meta']['event_code'] ?? null,
                'qty' => $draft['qty'],
            ]);
        }

        // Recalculate quotation totals
        $quotation->calculateTotals();
        $quotation->save();
    }
}

