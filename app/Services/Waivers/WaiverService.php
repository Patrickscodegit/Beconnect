<?php

namespace App\Services\Waivers;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use App\Services\CompositeItems\ConditionMatcherService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WaiverService
{
    public function __construct(
        private readonly ConditionMatcherService $conditionMatcher
    ) {}

    /**
     * Process all hinterland waivers for a quotation
     * Adds waivers that match conditions and removes those that don't
     *
     * @param QuotationRequest $quotation
     * @return void
     */
    public function processHinterlandWaivers(QuotationRequest $quotation): void
    {
        $hinterlandWaivers = $this->findHinterlandWaivers();

        if ($hinterlandWaivers->isEmpty()) {
            return;
        }

        foreach ($hinterlandWaivers as $waiver) {
            $conditions = $this->getWaiverConditions($waiver);
            
            if (empty($conditions)) {
                Log::warning('Hinterland waiver has no conditions defined', [
                    'waiver_id' => $waiver->id,
                    'waiver_name' => $waiver->article_name,
                ]);
                continue;
            }

            $shouldExist = $this->evaluateWaiverConditions($waiver, $conditions, $quotation);
            $existingWaiver = $this->findExistingWaiverInQuotation($waiver, $quotation);

            if ($shouldExist && !$existingWaiver) {
                $this->addWaiverToQuotation($waiver, $quotation);
            } elseif (!$shouldExist && $existingWaiver) {
                $this->removeWaiverFromQuotation($existingWaiver, $quotation);
            }
        }
    }

    /**
     * Find all hinterland waivers
     *
     * @return Collection<RobawsArticleCache>
     */
    public function findHinterlandWaivers(): Collection
    {
        return RobawsArticleCache::where('is_hinterland_waiver', true)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get conditions for a waiver
     * For now, conditions are defined here. Can be moved to database later.
     *
     * @param RobawsArticleCache $waiver
     * @return array|null
     */
    protected function getWaiverConditions(RobawsArticleCache $waiver): ?array
    {
        // Map waiver articles to their conditions
        $conditionsMap = [
            'Waiver Burkina Faso' => [
                'in_transit_to' => ['Burkina Faso', 'BFA'],
            ],
        ];

        return $conditionsMap[$waiver->article_name] ?? null;
    }

    /**
     * Evaluate if waiver conditions match the quotation
     *
     * @param RobawsArticleCache $waiver
     * @param array $conditions
     * @param QuotationRequest $quotation
     * @return bool
     */
    protected function evaluateWaiverConditions(
        RobawsArticleCache $waiver,
        array $conditions,
        QuotationRequest $quotation
    ): bool {
        try {
            return $this->conditionMatcher->matchConditions($conditions, $quotation);
        } catch (\Exception $e) {
            Log::error('Error evaluating waiver conditions', [
                'waiver_id' => $waiver->id,
                'waiver_name' => $waiver->article_name,
                'conditions' => $conditions,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find if waiver already exists in quotation
     *
     * @param RobawsArticleCache $waiver
     * @param QuotationRequest $quotation
     * @return QuotationRequestArticle|null
     */
    protected function findExistingWaiverInQuotation(
        RobawsArticleCache $waiver,
        QuotationRequest $quotation
    ): ?QuotationRequestArticle {
        return QuotationRequestArticle::where('quotation_request_id', $quotation->id)
            ->where('article_cache_id', $waiver->id)
            ->where('item_type', 'standalone')
            ->first();
    }

    /**
     * Add waiver to quotation
     *
     * @param RobawsArticleCache $waiver
     * @param QuotationRequest $quotation
     * @return void
     */
    protected function addWaiverToQuotation(
        RobawsArticleCache $waiver,
        QuotationRequest $quotation
    ): void {
        try {
            $role = $quotation->customer_role;
            $quantity = 1; // Waivers are typically per shipment

            QuotationRequestArticle::create([
                'quotation_request_id' => $quotation->id,
                'article_cache_id' => $waiver->id,
                'parent_article_id' => null, // Hinterland waivers are not attached to specific parents
                'item_type' => 'standalone', // Standalone since not attached to a parent article
                'quantity' => $quantity,
                'unit_type' => $waiver->unit_type ?? 'unit',
                'unit_price' => $waiver->unit_price ?? 0,
                'selling_price' => $waiver->getPriceForRole($role ?: 'default'),
                'currency' => $waiver->currency ?? 'EUR',
            ]);

            Log::info('Added hinterland waiver to quotation', [
                'quotation_id' => $quotation->id,
                'waiver_id' => $waiver->id,
                'waiver_name' => $waiver->article_name,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add hinterland waiver to quotation', [
                'quotation_id' => $quotation->id,
                'waiver_id' => $waiver->id,
                'waiver_name' => $waiver->article_name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Remove waiver from quotation
     *
     * @param QuotationRequestArticle $waiverArticle
     * @param QuotationRequest $quotation
     * @return void
     */
    protected function removeWaiverFromQuotation(
        QuotationRequestArticle $waiverArticle,
        QuotationRequest $quotation
    ): void {
        try {
            $waiverArticle->delete();

            Log::info('Removed hinterland waiver from quotation', [
                'quotation_id' => $quotation->id,
                'waiver_id' => $waiverArticle->article_cache_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove hinterland waiver from quotation', [
                'quotation_id' => $quotation->id,
                'waiver_article_id' => $waiverArticle->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

