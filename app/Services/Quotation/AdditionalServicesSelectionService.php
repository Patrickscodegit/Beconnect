<?php

namespace App\Services\Quotation;

use App\Enums\ServiceCategory;
use App\Models\QuotationAdditionalService;
use App\Models\QuotationRequest;
use App\Models\RobawsArticleCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AdditionalServicesSelectionService
{
    /**
     * Synchronise the automatic additional services for the given quotation and
     * return a grouped data structure that can be consumed by the UI.
     *
     * @return array<string, array<int, array>>
     */
    public function syncAndGetServices(QuotationRequest $quotation): array
    {
        $quotation->loadMissing([
            'articles' => fn ($query) => $query->withPivot(['item_type']),
            'additionalServices',
        ]);

        $parentArticles = $quotation->articles
            ->filter(fn (RobawsArticleCache $article) => $article->pivot?->item_type === 'parent');

        // If no parent articles are attached yet, there is nothing to suggest.
        if ($parentArticles->isEmpty()) {
            $this->cleanupOrphans($quotation, collect());

            return [];
        }

        $suggestions = collect();

        foreach ($parentArticles as $parent) {
            $matches = $this->findMatchingServicesForParent($parent);
            $suggestions = $suggestions->merge($matches);
        }

        $suggestions = $suggestions->unique('id');

        $persistedIds = collect();

        foreach ($suggestions as $article) {
            /** @var RobawsArticleCache $article */
            $service = QuotationAdditionalService::firstOrNew([
                'quotation_request_id' => $quotation->id,
                'robaws_article_cache_id' => $article->id,
            ]);

            $serviceCategory = $service->service_category ?? $this->inferServiceCategory($article);

            $service->service_category = $serviceCategory;
            $service->is_mandatory = $article->is_mandatory ?? false;

            if (!$service->exists) {
                $service->is_selected = $article->is_mandatory ?? false;
                $service->quantity = $this->defaultQuantity($service, $article);
                $service->unit_price = $article->unit_price;
                $service->notes = $article->notes;
            } else {
                // Preserve user adjustments while ensuring mandatory rows stay selected.
                if ($service->unit_price === null) {
                    $service->unit_price = $article->unit_price;
                }

                if ($service->notes === null && $article->notes) {
                    $service->notes = $article->notes;
                }

                if ($service->quantity === null) {
                    $service->quantity = $this->defaultQuantity($service, $article);
                }

                if ($service->is_mandatory) {
                    $service->is_selected = true;
                }
            }

            $service->total_price = $service->is_selected
                ? ($service->quantity ?? 0) * ($service->unit_price ?? 0)
                : 0;

            $service->save();
            $persistedIds->push($service->id);
        }

        $this->cleanupOrphans($quotation, $persistedIds);

        return $this->groupSuggestions($suggestions);
    }

    public function syncAdditionalServicePivot(
        QuotationRequest $quotation,
        QuotationAdditionalService $service,
        ?RobawsArticleCache $article = null
    ): void {
        $article ??= $service->article;

        if (!$article) {
            return;
        }

        if (!$service->service_category) {
            $service->service_category = $this->inferServiceCategory($article);
        }

        if ($service->is_mandatory) {
            $service->is_selected = true;
        }

        if ($service->quantity === null) {
            $service->quantity = $this->defaultQuantity($service, $article);
        }

        if ($service->unit_price === null) {
            $service->unit_price = $article->unit_price;
        }

        $service->total_price = $service->is_selected
            ? ($service->quantity ?? 0) * ($service->unit_price ?? 0)
            : 0;

        $service->save();
    }

    /**
     * Find surcharge articles that are relevant for the given parent article.
     */
    protected function findMatchingServicesForParent(RobawsArticleCache $parent): Collection
    {
        $eligibleTypes = [
            'LOCAL CHARGES POL',
            'LOCAL CHARGES POD',
            'SEAFREIGHT SURCHARGES',
            'ROAD TRANSPORT SURCHARGES',
            'INSPECTION SURCHARGES',
            'ADMINISTRATIVE / MISC. SURCHARGES',
            'AIRFREIGHT SURCHARGES',
        ];

        $query = RobawsArticleCache::query()
            ->where('is_active', true)
            ->where('id', '!=', $parent->id)
            ->where(function ($builder) {
                $builder->whereNull('is_parent_item')
                    ->orWhere('is_parent_item', false);
            })
            ->whereIn('article_type', $eligibleTypes);

        if ($parent->shipping_line) {
            $query->where(function ($builder) use ($parent) {
                $builder->where('shipping_line', $parent->shipping_line)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('shipping_line')
                            ->where('is_mandatory', true);
                    });
            });
        }

        if ($parent->transport_mode) {
            $query->where(function ($builder) use ($parent) {
                $builder->whereNull('transport_mode')
                    ->orWhere('transport_mode', $parent->transport_mode);
            });
        }

        if ($parent->pol_terminal) {
            $query->where(function ($builder) use ($parent) {
                $builder->whereNull('pol_terminal')
                    ->orWhere('pol_terminal', $parent->pol_terminal);
            });
        }

        return $query->get();
    }

    protected function inferServiceCategory(RobawsArticleCache $article): string
    {
        $type = $article->article_type;

        return match ($type) {
            'LOCAL CHARGES POL' => ServiceCategory::LOCAL_CHARGES_POL->value,
            'LOCAL CHARGES POD' => ServiceCategory::LOCAL_CHARGES_POD->value,
            'SEAFREIGHT SURCHARGES' => ServiceCategory::SEAFREIGHT_SURCHARGES->value,
            'ROAD TRANSPORT SURCHARGES' => ServiceCategory::ROAD_TRANSPORT_SURCHARGES->value,
            'INSPECTION SURCHARGES' => ServiceCategory::INSPECTION_SURCHARGES->value,
            'AIRFREIGHT SURCHARGES' => ServiceCategory::AIRFREIGHT_SURCHARGES->value,
            default => ServiceCategory::ADMINISTRATIVE_SURCHARGES->value,
        };
    }

    protected function defaultQuantity(
        QuotationAdditionalService $service,
        RobawsArticleCache $article
    ): float {
        if ($service->quantity !== null) {
            return (float) $service->quantity;
        }

        return $article->unit_type === 'percentage' ? 100.0 : 1.0;
    }

    protected function cleanupOrphans(QuotationRequest $quotation, Collection $persistedIds): void
    {
        QuotationAdditionalService::where('quotation_request_id', $quotation->id)
            ->when($persistedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $persistedIds))
            ->delete();
    }

    protected function groupSuggestions(Collection $articles): array
    {
        return $articles
            ->groupBy(fn (RobawsArticleCache $article) => $this->inferServiceCategory($article))
            ->map(fn ($group) => $group->map(function (RobawsArticleCache $article) {
                return [
                    'id' => $article->id,
                    'name' => $article->article_name,
                    'article_type' => $article->article_type,
                    'cost_side' => $article->cost_side,
                    'is_mandatory' => (bool) ($article->is_mandatory ?? false),
                    'unit_price' => $article->unit_price,
                    'notes' => $article->notes,
                ];
            })->values()->all())
            ->toArray();
    }
}

