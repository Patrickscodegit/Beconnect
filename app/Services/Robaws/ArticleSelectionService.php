<?php

namespace App\Services\Robaws;

use App\Models\ShippingSchedule;
use App\Models\RobawsArticleCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ArticleSelectionService
{
    public function __construct(
        private RobawsArticleProvider $articleProvider
    ) {}

    /**
     * Suggest articles for an offer based on schedule and cargo details
     */
    public function suggestArticlesForOffer(
        ShippingSchedule $schedule,
        array $cargoDetails,
        string $serviceType
    ): array {
        Log::info('Suggesting articles for offer', [
            'schedule_id' => $schedule->id,
            'carrier' => $schedule->carrier->code ?? 'unknown',
            'service_type' => $serviceType
        ]);

        // Get carrier code
        $carrierCode = $schedule->carrier->code ?? null;

        // Determine required components
        $requiredComponents = $this->determineRequiredComponents($serviceType, $cargoDetails);

        // Get all applicable articles
        $articles = $this->getApplicableArticles($carrierCode, $serviceType);

        // Filter and organize articles by component
        $suggestions = [
            'mandatory' => [],
            'optional' => [],
            'recommended' => []
        ];

        foreach ($articles as $article) {
            $component = $this->identifyComponent($article, $requiredComponents);

            if ($component['is_mandatory']) {
                $suggestions['mandatory'][] = [
                    'id' => $article->id,
                    'robaws_article_id' => $article->robaws_article_id,
                    'article_code' => $article->article_code,
                    'article_name' => $article->article_name,
                    'category' => $article->category,
                    'unit_price' => $article->unit_price,
                    'currency' => $article->currency,
                    'reason' => $component['reason'],
                ];
            } elseif ($component['is_recommended']) {
                $suggestions['recommended'][] = [
                    'id' => $article->id,
                    'robaws_article_id' => $article->robaws_article_id,
                    'article_code' => $article->article_code,
                    'article_name' => $article->article_name,
                    'category' => $article->category,
                    'unit_price' => $article->unit_price,
                    'currency' => $article->currency,
                    'reason' => $component['reason'],
                ];
            } else {
                $suggestions['optional'][] = [
                    'id' => $article->id,
                    'robaws_article_id' => $article->robaws_article_id,
                    'article_code' => $article->article_code,
                    'article_name' => $article->article_name,
                    'category' => $article->category,
                    'unit_price' => $article->unit_price,
                    'currency' => $article->currency,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Determine required components based on service type
     */
    private function determineRequiredComponents(string $serviceType, array $cargoDetails): array
    {
        $components = [
            'seafreight' => ['mandatory' => true, 'reason' => 'Ocean freight is required'],
        ];

        // Add export/import specific components
        if (str_contains($serviceType, 'EXPORT')) {
            $components['customs_origin'] = ['mandatory' => true, 'reason' => 'Export customs clearance required'];
        }

        if (str_contains($serviceType, 'IMPORT')) {
            $components['customs_destination'] = ['mandatory' => true, 'reason' => 'Import customs clearance required'];
        }

        // Vehicle-specific components
        $commodityType = $cargoDetails['type'] ?? '';
        if (in_array(strtolower($commodityType), ['cars', 'vehicles', 'motorcycles', 'trucks'])) {
            $components['warehouse'] = ['recommended' => true, 'reason' => 'Warehouse services recommended for vehicles'];
        }

        // Insurance is always recommended
        $components['insurance'] = ['recommended' => true, 'reason' => 'Cargo insurance recommended'];

        return $components;
    }

    /**
     * Get applicable articles for carrier and service type
     */
    private function getApplicableArticles(?string $carrierCode, string $serviceType): Collection
    {
        $query = RobawsArticleCache::active();

        if ($carrierCode) {
            $query->forCarrier($carrierCode);
        }

        $query->forService($serviceType);

        return $query->get();
    }

    /**
     * Identify which component an article belongs to
     */
    private function identifyComponent(RobawsArticleCache $article, array $requiredComponents): array
    {
        $category = $article->category;

        if (isset($requiredComponents[$category])) {
            return [
                'is_mandatory' => $requiredComponents[$category]['mandatory'] ?? false,
                'is_recommended' => $requiredComponents[$category]['recommended'] ?? false,
                'reason' => $requiredComponents[$category]['reason'] ?? ''
            ];
        }

        return [
            'is_mandatory' => false,
            'is_recommended' => false,
            'reason' => ''
        ];
    }

    /**
     * Filter articles by applicability to a schedule
     */
    public function filterApplicableArticles(
        Collection $articles,
        ShippingSchedule $schedule
    ): Collection {
        $carrierCode = $schedule->carrier->code ?? null;

        if (!$carrierCode) {
            return $articles;
        }

        return $articles->filter(function ($article) use ($carrierCode) {
            return $article->isApplicableForCarrier($carrierCode);
        });
    }

    /**
     * Get articles for quotation based on criteria (NEW - Phase 4)
     * Intelligently filters articles based on schedule, service type, shipping line, and terminal
     * 
     * @param array $criteria Keys: selected_schedule_id, service_type, shipping_line, pol_terminal
     * @return Collection Filtered articles
     */
    public function getArticlesForQuotation(array $criteria): Collection
    {
        $query = RobawsArticleCache::active();
        
        // If schedule selected → filter by carrier + terminal
        if (!empty($criteria['selected_schedule_id'])) {
            $schedule = ShippingSchedule::find($criteria['selected_schedule_id']);
            if ($schedule) {
                $query->forSchedule($schedule);
            }
        }
        
        // Filter by service type
        if (!empty($criteria['service_type'])) {
            $query->forServiceType($criteria['service_type']);
        }
        
        // Filter by shipping line
        if (!empty($criteria['shipping_line'])) {
            $query->forShippingLine($criteria['shipping_line']);
        }
        
        // Filter by POL terminal
        if (!empty($criteria['pol_terminal'])) {
            $query->forPolTerminal($criteria['pol_terminal']);
        }
        
        // Only show valid articles
        $query->validAsOf(now());
        
        Log::info('Articles filtered for quotation', [
            'criteria' => $criteria,
            'article_count' => $query->count()
        ]);
        
        return $query->get();
    }

    /**
     * Auto-expand parent articles to include their composite items (surcharges)
     * 
     * @param Collection $selectedArticles Articles selected by user
     * @return Collection Expanded collection including all child articles
     */
    public function expandParentArticles(Collection $selectedArticles): Collection
    {
        $expandedArticles = collect();
        
        foreach ($selectedArticles as $article) {
            // Add the main article
            $expandedArticles->push($article);
            
            // If parent item → add all child surcharges
            if ($article->is_parent_item && $article->children()->exists()) {
                $children = $article->children()->get();
                
                Log::info('Expanding parent article with children', [
                    'parent' => $article->article_name,
                    'children_count' => $children->count()
                ]);
                
                $expandedArticles = $expandedArticles->merge($children);
            }
        }
        
        // Remove duplicates
        return $expandedArticles->unique('id');
    }

    /**
     * Get articles with their children for display (parent-child hierarchy)
     * Useful for showing expandable article lists in UI
     * 
     * @param Collection $articles Parent articles
     * @return array Structured array with parents and their children
     */
    public function getArticlesWithChildren(Collection $articles): array
    {
        $structured = [];
        
        foreach ($articles as $article) {
            $item = [
                'article' => $article,
                'children' => [],
            ];
            
            if ($article->is_parent_item) {
                $item['children'] = $article->children()
                    ->with('pivot')
                    ->get()
                    ->map(function ($child) {
                        return [
                            'article' => $child,
                            'cost_type' => $child->pivot->cost_type,
                            'default_quantity' => $child->pivot->default_quantity,
                            'default_cost_price' => $child->pivot->default_cost_price,
                            'unit_type' => $child->pivot->unit_type,
                            'is_required' => $child->pivot->is_required,
                        ];
                    })
                    ->toArray();
            }
            
            $structured[] = $item;
        }
        
        return $structured;
    }

    /**
     * Get parent articles only (main freight services)
     * Useful for initial selection UI
     * 
     * @param array $criteria Same as getArticlesForQuotation
     * @return Collection Parent articles only
     */
    public function getParentArticlesForQuotation(array $criteria): Collection
    {
        $articles = $this->getArticlesForQuotation($criteria);
        
        return $articles->filter(function ($article) {
            return $article->is_parent_item === true;
        });
    }
}

