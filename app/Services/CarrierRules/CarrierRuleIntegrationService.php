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

            // Sync surcharge articles: remove old ones no longer applicable, add/update new ones
            $this->syncSurchargeArticles($quotation, $result->quoteLineDrafts, $item);
            
            // Remove articles that no longer match any commodity items (non-carrier-rule articles)
            $this->removeNonMatchingArticles($quotation);

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
     * Sync surcharge articles: remove old ones no longer applicable, add/update new ones
     */
    private function syncSurchargeArticles(
        QuotationRequest $quotation,
        array $quoteLineDrafts,
        QuotationCommodityItem $item
    ): void {
        // Get all existing carrier rule articles linked to this commodity item
        $existingArticles = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
            ->where(function ($query) use ($item) {
                $query->whereJsonContains('notes->commodity_item_id', $item->id)
                    ->orWhere('notes', 'like', '%"commodity_item_id":' . $item->id . '%');
            })
            ->where(function ($query) {
                $query->whereJsonContains('notes->carrier_rule_applied', true)
                    ->orWhere('notes', 'like', '%"carrier_rule_applied":true%');
            })
            ->get();

        // Build a map of new drafts by event_code for quick lookup
        $newDraftsByEventCode = [];
        foreach ($quoteLineDrafts as $draft) {
            $eventCode = $draft['meta']['event_code'] ?? null;
            if ($eventCode) {
                $newDraftsByEventCode[$eventCode] = $draft;
            }
        }

        // Remove articles that are no longer applicable
        foreach ($existingArticles as $existingArticle) {
            $notes = json_decode($existingArticle->notes ?? '{}', true);
            $eventCode = $notes['event_code'] ?? null;
            $linkedItemId = $notes['commodity_item_id'] ?? null;

            // Remove if:
            // 1. Linked to this commodity item AND
            // 2. Event code is not in the new drafts (no longer applicable)
            if ($linkedItemId == $item->id && $eventCode && !isset($newDraftsByEventCode[$eventCode])) {
                Log::info('CarrierRuleIntegration: Removing no longer applicable article', [
                    'quotation_id' => $quotation->id,
                    'article_id' => $existingArticle->id,
                    'event_code' => $eventCode,
                    'commodity_item_id' => $item->id,
                ]);
                $existingArticle->delete();
            }
        }

        // Add/update articles from new drafts
        $this->addSurchargeArticles($quotation, $quoteLineDrafts, $item);
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
            // Check if article already exists for this quotation with same event code
            $eventCode = $draft['meta']['event_code'] ?? null;
            $existingArticle = null;
            
            if ($eventCode) {
                $existingArticle = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                    ->where('article_cache_id', $draft['article_id'])
                    ->where(function ($query) use ($eventCode) {
                        $query->whereJsonContains('notes->event_code', $eventCode)
                            ->orWhere('notes', 'like', '%' . $eventCode . '%');
                    })
                    ->first();
            } else {
                // Fallback: check by article ID only
                $existingArticle = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                    ->where('article_cache_id', $draft['article_id'])
                    ->first();
            }

            if ($existingArticle) {
                // Update quantity and notes if needed
                if ($draft['qty'] > 0) {
                    $existingArticle->quantity = $draft['qty'];
                }
                
                // Update notes to ensure commodity_item_id is set
                $notes = json_decode($existingArticle->notes ?? '{}', true);
                $notes['carrier_rule_applied'] = true;
                $notes['event_code'] = $draft['meta']['event_code'] ?? null;
                $notes['reason'] = $draft['meta']['reason'] ?? null;
                $notes['matched_rule_id'] = $draft['meta']['matched_rule_id'] ?? null;
                $notes['commodity_item_id'] = $item->id;
                $existingArticle->notes = json_encode($notes);
                
                $existingArticle->save();
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

    /**
     * Remove articles that no longer match any commodity items
     * This is called when commodity item categories change
     */
    private function removeNonMatchingArticles(QuotationRequest $quotation): void
    {
        // Get all commodity items for this quotation
        $commodityItems = $quotation->commodityItems;
        if ($commodityItems->isEmpty()) {
            return; // No commodity items, nothing to check
        }

        // Get all articles that are NOT carrier rule-based
        // Exclude articles that have carrier_rule_applied:true in their notes
        $articles = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
            ->where(function ($query) {
                // Articles where notes is null OR notes doesn't contain carrier_rule_applied:true
                $query->whereNull('notes')
                    ->orWhere('notes', 'not like', '%"carrier_rule_applied":true%')
                    ->orWhere('notes', 'not like', "%'carrier_rule_applied':true%");
            })
            ->with('articleCache')
            ->get();

        foreach ($articles as $articleRecord) {
            $article = $articleRecord->articleCache;
            if (!$article || !$article->commodity_type) {
                continue; // Skip articles without commodity type
            }

            // Check if this article matches any commodity items
            $matchingItems = QuotationCommodityItem::findMatchingCommodityItems(
                $commodityItems,
                $article->commodity_type
            );

            // If no matching items, remove the article
            if ($matchingItems->isEmpty()) {
                Log::info('CarrierRuleIntegration: Removing article that no longer matches commodity items', [
                    'quotation_id' => $quotation->id,
                    'article_id' => $articleRecord->id,
                    'article_name' => $article->article_name,
                    'commodity_type' => $article->commodity_type,
                ]);
                $articleRecord->delete();
            }
        }
    }
}

