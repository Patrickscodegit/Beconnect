<?php

namespace App\Services\CarrierRules;

use App\Models\Port;
use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Service to integrate CarrierRuleEngine into quotation flow
 */
class CarrierRuleIntegrationService
{
    public function __construct(
        private CarrierRuleEngine $engine,
        private CarrierRuleResolver $resolver
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

        Log::info('CarrierRuleIntegration: Processing commodity item', [
            'item_id' => $item->id,
            'line_number' => $item->line_number,
            'category' => $item->category,
            'commodity_type' => $item->commodity_type,
            'relationship_type' => $item->relationship_type,
            'related_item_id' => $item->related_item_id,
            'commodity_item_id_in_input' => $input->commodityItemId,
            'carrier_id' => $input->carrierId,
            'pod_port_id' => $input->podPortId,
        ]);

        // Process through engine
        try {
            $result = $this->engine->processCargo($input);
            
            Log::info('CarrierRuleIntegration: Engine processed item', [
                'item_id' => $item->id,
                'surcharge_events_count' => count($result->surchargeEvents),
                'surcharge_event_codes' => collect($result->surchargeEvents)->pluck('event_code')->toArray(),
                'towing_events' => collect($result->surchargeEvents)->filter(fn($e) => 
                    ($e['event_code'] ?? '') === 'TOWING' || ($e['event_code'] ?? '') === 'TOWING_WAF'
                )->toArray(),
            ]);

            // Store chargeable LM and meta
            $item->chargeable_lm = $result->chargeableMeasure->chargeableLm;
            // Also update the lm field so the UI displays the correct value
            $item->lm = $result->chargeableMeasure->chargeableLm;
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

            // Sync carrier clauses to quotation (for display on customer/admin/PDF)
            $this->syncCarrierClauses($quotation, $input->carrierId, $input->podPortId, $input->vesselName, $input->vesselClass);
            
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

    private function syncCarrierClauses(
        QuotationRequest $quotation,
        int $carrierId,
        ?int $portId,
        ?string $vesselName,
        ?string $vesselClass
    ): void {
        $clauses = $this->resolver->resolveClauses(
            $carrierId,
            $portId,
            $vesselName,
            $vesselClass
        );

        $normalized = $clauses->map(function ($clause) {
            return [
                'carrier_id' => $clause->carrier_id,
                'port_id' => $clause->port_id,
                'vessel_name' => $clause->vessel_name,
                'vessel_class' => $clause->vessel_class,
                'clause_type' => $clause->clause_type,
                'text' => $clause->text,
                'sort_order' => $clause->sort_order,
            ];
        })->values()->toArray();

        $quotation->carrier_clauses = $normalized;
        $quotation->saveQuietly();
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
        $hasCarrierRuleColumns = Schema::hasColumn('quotation_request_articles', 'carrier_rule_applied')
            && Schema::hasColumn('quotation_request_articles', 'carrier_rule_commodity_item_id')
            && Schema::hasColumn('quotation_request_articles', 'carrier_rule_event_code');
        if (!$hasCarrierRuleColumns) {
            Log::warning('CarrierRuleIntegration: carrier rule columns missing, skipping sync', [
                'quotation_id' => $quotation->id,
                'item_id' => $item->id,
            ]);
            return;
        }

        $existingArticlesQuery = QuotationRequestArticle::where('quotation_request_id', $quotation->id);

        $existingArticlesQuery->where('carrier_rule_applied', true)
            ->where('carrier_rule_commodity_item_id', $item->id);

        $existingArticles = $existingArticlesQuery->get();

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
            $eventCode = $existingArticle->carrier_rule_event_code ?? null;
            $linkedItemId = $existingArticle->carrier_rule_commodity_item_id ?? null;

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
        $hasCarrierRuleColumns = Schema::hasColumn('quotation_request_articles', 'carrier_rule_applied')
            && Schema::hasColumn('quotation_request_articles', 'carrier_rule_commodity_item_id')
            && Schema::hasColumn('quotation_request_articles', 'carrier_rule_event_code');
        if (!$hasCarrierRuleColumns) {
            Log::warning('CarrierRuleIntegration: carrier rule columns missing, skipping surcharge add', [
                'quotation_id' => $quotation->id,
                'item_id' => $item->id,
            ]);
            return;
        }

        foreach ($quoteLineDrafts as $draft) {
            // Check if article already exists for this quotation with same event code
            $eventCode = $draft['meta']['event_code'] ?? null;
            $existingArticle = null;
            
            if ($eventCode) {
                $existingArticle = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                    ->where('article_cache_id', $draft['article_id'])
                    ->where('carrier_rule_event_code', $eventCode)
                    ->where('carrier_rule_commodity_item_id', $item->id)
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
                
                $existingArticle->carrier_rule_applied = true;
                $existingArticle->carrier_rule_event_code = $draft['meta']['event_code'] ?? null;
                $existingArticle->carrier_rule_commodity_item_id = $item->id;
                
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
                'carrier_rule_applied' => true,
                'carrier_rule_event_code' => $draft['meta']['event_code'] ?? null,
                'carrier_rule_commodity_item_id' => $item->id,
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
     * Public method to remove articles that no longer match
     * Can be called from observers or other services when POD changes
     */
    public function removeNonMatchingArticles(QuotationRequest $quotation): void
    {
        // Get all commodity items for this quotation
        $commodityItems = $quotation->commodityItems;
        if ($commodityItems->isEmpty()) {
            return; // No commodity items, nothing to check
        }

        // Get all articles that are NOT carrier rule-based
        $hasCarrierRuleColumns = Schema::hasColumn('quotation_request_articles', 'carrier_rule_applied');
        if (!$hasCarrierRuleColumns) {
            Log::warning('CarrierRuleIntegration: carrier rule columns missing, skipping non-matching removal', [
                'quotation_id' => $quotation->id,
            ]);
            return;
        }

        $articlesQuery = QuotationRequestArticle::where('quotation_request_id', $quotation->id);

        $articlesQuery->where(function ($query) {
            $query->whereNull('carrier_rule_applied')
                ->orWhere('carrier_rule_applied', false);
        });

        $articles = $articlesQuery->with('articleCache')->get();

        // Extract POD code from quotation's POD for matching
        $quotationPodCode = null;
        $quotationPodName = null;
        $quotationPodPortId = $quotation->pod_port_id ?? null;
        if (!empty($quotation->pod)) {
            // Extract code from format "City (CODE), Country"
            if (preg_match('/\(([A-Z0-9]+)\)/', $quotation->pod, $matches)) {
                $quotationPodCode = strtoupper(trim($matches[1]));
            }
            // Extract name (everything before comma or parentheses)
            $podParts = preg_split('/[,(]/', $quotation->pod);
            $quotationPodName = trim($podParts[0] ?? '');
        }

        // Get carrier rule mappings to check if articles are explicitly mapped (Freight Mapping)
        $carrierRuleMappedArticleIds = app(\App\Services\SmartArticleSelectionService::class)
            ->getStrictMappedArticleIdsForQuotation($quotation);

        foreach ($articles as $articleRecord) {
            $article = $articleRecord->articleCache;
            if (!$article) {
                continue; // Skip articles without cache
            }

            $shouldRemove = false;
            $removalReason = '';

            // Check 0: If article is mapped via carrier rules (Freight Mapping), don't remove it
            // It's explicitly allowed regardless of commodity type matching
            if (
                in_array($article->id, $carrierRuleMappedArticleIds, true)
                || in_array($articleRecord->parent_article_id, $carrierRuleMappedArticleIds, true)
            ) {
                continue; // Skip removal check for carrier rule mapped articles
            }

            // Check 1: Enforce strict mapping when available
            if (!empty($carrierRuleMappedArticleIds)) {
                $isMapped = in_array($article->id, $carrierRuleMappedArticleIds, true)
                    || in_array($articleRecord->parent_article_id, $carrierRuleMappedArticleIds, true);

                if (!$isMapped) {
                    $shouldRemove = true;
                    $removalReason = 'not in strict carrier mapping';
                }
            }

            // Check 2: Article must have matching commodity type
            if (!$shouldRemove && $article->commodity_type) {
                $matchingItems = QuotationCommodityItem::findMatchingCommodityItems(
                    $commodityItems,
                    $article->commodity_type
                );

                // If no matching items, remove the article
                if ($matchingItems->isEmpty()) {
                    $shouldRemove = true;
                    $removalReason = 'no matching commodity items';
                }
            }

            // Check 3: Article POD must match quotation POD (if article has POD)
            if (
                !$shouldRemove &&
                ((isset($article->pod_port_id) && $article->pod_port_id) || !empty($article->pod)) &&
                ($quotationPodPortId || !empty($quotation->pod))
            ) {
                $articlePodCode = null;
                $articlePodName = null;
                $articlePodPortId = $article->pod_port_id ?? null;
                
                // Extract code from article POD
                if (preg_match('/\(([A-Z0-9]+)\)/', $article->pod, $matches)) {
                    $articlePodCode = strtoupper(trim($matches[1]));
                }
                // Extract name from article POD
                $articlePodParts = preg_split('/[,(]/', $article->pod);
                $articlePodName = trim($articlePodParts[0] ?? '');

                // Check if PODs match (by code or name)
                $podMatches = false;
                if ($quotationPodPortId && $articlePodPortId) {
                    $podMatches = ((int) $quotationPodPortId === (int) $articlePodPortId);
                } elseif ($quotationPodCode && $articlePodCode) {
                    $podMatches = ($quotationPodCode === $articlePodCode);
                } elseif ($quotationPodName && $articlePodName) {
                    // Case-insensitive name match
                    $podMatches = (strcasecmp($quotationPodName, $articlePodName) === 0);
                } elseif ($quotation->pod && $article->pod) {
                    // Fallback: check if quotation POD is contained in article POD or vice versa
                    $podMatches = (stripos($article->pod, $quotation->pod) !== false) ||
                                  (stripos($quotation->pod, $article->pod) !== false);
                }

                // If POD doesn't match, remove the article
                if (!$podMatches) {
                    $shouldRemove = true;
                    $removalReason = 'POD mismatch: article POD "' . $article->pod . '" does not match quotation POD "' . $quotation->pod . '"';
                }
            }

            if ($shouldRemove) {
                Log::info('CarrierRuleIntegration: Removing article that no longer matches', [
                    'quotation_id' => $quotation->id,
                    'article_id' => $articleRecord->id,
                    'article_name' => $article->article_name ?? 'N/A',
                    'article_pod' => $article->pod ?? 'N/A',
                    'quotation_pod' => $quotation->pod ?? 'N/A',
                    'removal_reason' => $removalReason,
                ]);
                $articleRecord->delete();
            }
        }
    }
}

