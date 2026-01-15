<?php

namespace App\Services\Quotation;

use App\Enums\ServiceCategory;
use App\Models\QuotationAdditionalService;
use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
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
        $this->syncSelectedServicesToArticles($quotation);

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
        $this->syncSelectedServicesToArticles($quotation);
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

    protected function syncSelectedServicesToArticles(QuotationRequest $quotation): void
    {
        $quotation->loadMissing(['commodityItems', 'additionalServices.article']);

        $selectedServices = $quotation->additionalServices
            ->filter(fn (QuotationAdditionalService $service) => (bool) $service->is_selected);

        $contexts = $this->buildStackContexts($quotation);
        if ($selectedServices->isEmpty() || empty($contexts)) {
            $this->removeAutoServiceLines($quotation, []);
            return;
        }

        $desiredKeys = [];

        foreach ($selectedServices as $service) {
            $article = $service->article;
            if (!$article) {
                continue;
            }

            foreach ($contexts as $context) {
                $desiredKeys[] = [
                    'article_id' => $article->id,
                    'base_item_id' => $context['base_item_id'],
                    'is_combination' => $context['is_combination'],
                ];

                $note = $this->buildServiceNote($article, $context);
                $formulaInputs = $this->buildServiceContextPayload($article, $context);

                $existing = QuotationRequestArticle::query()
                    ->where('quotation_request_id', $quotation->id)
                    ->where('article_cache_id', $article->id)
                    ->where('formula_inputs->service_context->source', 'selected_service')
                    ->where('formula_inputs->service_context->base_item_id', $context['base_item_id'])
                    ->first();

                if ($existing) {
                    $existing->quantity = $service->quantity ?? 1;
                    $existing->unit_price = $service->unit_price ?? $article->unit_price ?? 0;
                    $existing->selling_price = $service->unit_price ?? $article->unit_price ?? 0;
                    $existing->notes = $note;
                    $existing->formula_inputs = array_merge($existing->formula_inputs ?? [], $formulaInputs);
                    $existing->saveQuietly();
                } else {
                    QuotationRequestArticle::create([
                        'quotation_request_id' => $quotation->id,
                        'article_cache_id' => $article->id,
                        'item_type' => 'standalone',
                        'quantity' => $service->quantity ?? 1,
                        'unit_type' => $article->unit_type ?? 'unit',
                        'unit_price' => $service->unit_price ?? $article->unit_price ?? 0,
                        'selling_price' => $service->unit_price ?? $article->unit_price ?? 0,
                        'subtotal' => 0,
                        'currency' => $article->currency ?? 'EUR',
                        'notes' => $note,
                        'formula_inputs' => $formulaInputs,
                    ]);
                }

                $this->applyServiceToCommodityItem($context, $article, $service);
            }
        }

        $this->removeAutoServiceLines($quotation, $desiredKeys);
    }

    protected function removeAutoServiceLines(QuotationRequest $quotation, array $desiredKeys): void
    {
        $existing = QuotationRequestArticle::query()
            ->where('quotation_request_id', $quotation->id)
            ->where('formula_inputs->service_context->source', 'selected_service')
            ->get();

        $desiredLookup = collect($desiredKeys)->map(function (array $entry) {
            return sprintf('%s:%s', $entry['article_id'], $entry['base_item_id']);
        })->flip();

        foreach ($existing as $article) {
            $context = $article->formula_inputs['service_context'] ?? [];
            $key = sprintf('%s:%s', $article->article_cache_id, $context['base_item_id'] ?? 'n/a');

            if (!$desiredLookup->has($key)) {
                $article->delete();
            }
        }
    }

    protected function buildStackContexts(QuotationRequest $quotation): array
    {
        $contexts = [];
        $stacks = QuotationCommodityItem::getAllStacks($quotation->id);

        foreach ($stacks as $stack) {
            $baseItem = $stack->first(function ($candidate) {
                return $candidate->id === $candidate->getStackGroup();
            }) ?? $stack->first();

            if (!$baseItem) {
                continue;
            }

            $categories = $stack->pluck('category')->filter()->unique()->values()->all();
            $commodityTypes = $stack->pluck('commodity_type')->filter()->unique()->values()->all();
            $lineNumbers = $stack->pluck('line_number')->filter()->sort()->values()->all();
            $isCombination = $stack->count() > 1 || $stack->contains(fn ($item) => !$item->isSeparate());

            $contexts[] = [
                'base_item_id' => $baseItem->id,
                'base_line_number' => $baseItem->line_number,
                'categories' => $categories,
                'commodity_types' => $commodityTypes,
                'line_numbers' => $lineNumbers,
                'member_ids' => $stack->pluck('id')->all(),
                'is_combination' => $isCombination,
            ];
        }

        usort($contexts, fn ($a, $b) => ($a['base_line_number'] ?? 0) <=> ($b['base_line_number'] ?? 0));

        return $contexts;
    }

    protected function buildServiceContextPayload(RobawsArticleCache $article, array $context): array
    {
        return [
            'service_context' => [
                'source' => 'selected_service',
                'service_article_id' => $article->id,
                'base_item_id' => $context['base_item_id'],
                'base_line_number' => $context['base_line_number'],
                'commodity_types' => $context['commodity_types'],
                'vehicle_categories' => $context['categories'],
                'line_numbers' => $context['line_numbers'],
                'is_combination' => $context['is_combination'],
            ],
        ];
    }

    protected function buildServiceNote(RobawsArticleCache $article, array $context): string
    {
        $typeLabel = $context['is_combination'] ? 'combination' : 'base unit';
        $commodityTypes = implode(', ', $context['commodity_types'] ?: ['n/a']);
        $categories = implode(', ', $context['categories'] ?: ['n/a']);
        $lines = implode(', ', $context['line_numbers'] ?: []);
        $averageNote = $this->hasTrailerCategory($context['categories'])
            ? ' Note: dimensions may use average trailer measurements when missing.'
            : '';

        return sprintf(
            'Auto-added service for %s (lines %s). Commodity type(s): %s. Vehicle category(s): %s.%s',
            $typeLabel,
            $lines ?: 'n/a',
            $commodityTypes,
            $categories,
            $averageNote
        );
    }

    protected function applyServiceToCommodityItem(array $context, RobawsArticleCache $article, QuotationAdditionalService $service): void
    {
        $baseItem = QuotationCommodityItem::find($context['base_item_id']);
        if (!$baseItem) {
            return;
        }

        $meta = $baseItem->carrier_rule_meta ?? [];
        $services = $meta['selected_services'] ?? [];
        $services[] = [
            'service_article_id' => $article->id,
            'service_name' => $article->article_name,
            'service_category' => $service->service_category,
            'base_item_id' => $context['base_item_id'],
            'line_numbers' => $context['line_numbers'],
            'commodity_types' => $context['commodity_types'],
            'vehicle_categories' => $context['categories'],
            'is_combination' => $context['is_combination'],
        ];

        $meta['selected_services'] = collect($services)
            ->unique(fn ($entry) => ($entry['service_article_id'] ?? '') . ':' . ($entry['base_item_id'] ?? ''))
            ->values()
            ->all();

        $baseItem->carrier_rule_meta = $meta;
        $baseItem->saveQuietly();
    }

    protected function hasTrailerCategory(array $categories): bool
    {
        foreach ($categories as $category) {
            if (in_array($category, ['trailer', 'trailer_stack', 'tank_trailer'], true)) {
                return true;
            }
        }

        return false;
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

