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
}

