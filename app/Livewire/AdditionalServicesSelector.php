<?php

namespace App\Livewire;

use App\Enums\ServiceCategory;
use App\Models\QuotationAdditionalService;
use App\Models\QuotationRequest;
use App\Models\RobawsArticleCache;
use App\Services\Quotation\AdditionalServicesSelectionService;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class AdditionalServicesSelector extends Component
{
    public int $quotationId;
    public ?QuotationRequest $quotation = null;

    /** @var array<int, array> */
    public array $serviceItems = [];

    /** @var array<int, bool> */
    public array $selected = [];

    /** @var array<int, float> */
    public array $quantities = [];

    /**
     * Totals per category and grand total.
     *
     * [
     *   'categories' => ['LOCAL_CHARGES_POL' => 123.45, ...],
     *   'grand' => 456.78
     * ]
     */
    public array $totals = [
        'categories' => [],
        'grand' => 0,
    ];

    public bool $loading = false;

    protected $listeners = [
        'schedule-selected' => 'reloadFromContext',
        'ports-updated' => 'reloadFromContext',
        'commodity-item-saved' => 'reloadFromContext',
    ];

    public function mount(int $quotationId): void
    {
        $this->quotationId = $quotationId;
        $this->loadServices();
    }

    public function reloadFromContext(): void
    {
        $this->loadServices();
    }

    public function toggleService(int $articleId): void
    {
        if (!isset($this->serviceItems[$articleId])) {
            return;
        }

        if ($this->serviceItems[$articleId]['is_mandatory']) {
            return; // mandatory items cannot be toggled off
        }

        $this->selected[$articleId] = !($this->selected[$articleId] ?? false);
        if (($this->selected[$articleId] ?? false) && ($this->quantities[$articleId] ?? 0) <= 0) {
            $this->quantities[$articleId] = 1;
        }
        $this->persistSingle($articleId);
        $this->recalculateTotals();
        $this->dispatch('additionalServicesUpdated');
    }

    public function updatedQuantities($value, $key): void
    {
        $articleId = (int) $key;

        if (!isset($this->serviceItems[$articleId])) {
            return;
        }

        $quantity = max(0, (float) $value);
        $this->quantities[$articleId] = $quantity;
        $this->persistSingle($articleId);
        $this->recalculateTotals();
        $this->dispatch('additionalServicesUpdated');
    }

    public function render()
    {
        $grouped = $this->groupItemsByCategory();

        return view('livewire.additional-services-selector', [
            'groupedServices' => $grouped,
            'totals' => $this->totals,
            'loading' => $this->loading,
        ]);
    }

    protected function loadServices(): void
    {
        $this->loading = true;

        /** @var QuotationRequest $quotation */
        $quotation = QuotationRequest::with([
            'commodityItems',
            'additionalServices.article',
            'selectedSchedule.carrier',
        ])->findOrFail($this->quotationId);
        $this->quotation = $quotation;

        /** @var AdditionalServicesSelectionService $service */
        $service = app(AdditionalServicesSelectionService::class);
        $service->syncAndGetServices($quotation);

        $quotation->load(['additionalServices.article']);
        $persisted = $quotation->additionalServices;

        $this->serviceItems = [];
        $this->selected = [];
        $this->quantities = [];

        $persisted->each(function (QuotationAdditionalService $persistedRow) {
            $article = $persistedRow->article;
            if (!$article) {
                return;
            }

            $articleId = $article->id;
            $categoryValue = $persistedRow->service_category ?? ServiceCategory::ADMINISTRATIVE_SURCHARGES->value;

            $this->serviceItems[$articleId] = [
                'article_id' => $articleId,
                'name' => $article->article_name,
                'article_type' => $article->article_type,
                'cost_side' => $article->cost_side,
                'transport_mode' => $article->transport_mode,
                'category' => $categoryValue,
                'is_mandatory' => (bool) $persistedRow->is_mandatory,
                'unit_price' => (float) ($persistedRow->unit_price ?? 0),
                'notes' => $article->notes,
            ];

            $this->selected[$articleId] = (bool) $persistedRow->is_selected;
            $this->quantities[$articleId] = (float) ($persistedRow->quantity ?? 1);
        });

        $this->recalculateTotals();
        $this->loading = false;
        $this->dispatch('additionalServicesUpdated', [
            'quotation_id' => $this->quotationId,
            'totals' => $this->totals,
        ]);
    }

    protected function persistSingle(int $articleId): void
    {
        if (!isset($this->serviceItems[$articleId])) {
            return;
        }

        $item = $this->serviceItems[$articleId];
        $selected = $this->selected[$articleId] ?? false;
        $quantity = $this->quantities[$articleId] ?? 0;
        $total = $selected ? $item['unit_price'] * $quantity : 0;

        try {
            /** @var QuotationAdditionalService $model */
            $model = QuotationAdditionalService::updateOrCreate(
                [
                    'quotation_request_id' => $this->quotationId,
                    'robaws_article_cache_id' => $articleId,
                ],
                [
                    'service_category' => $item['category'],
                    'is_mandatory' => $item['is_mandatory'],
                    'is_selected' => $selected,
                    'quantity' => $quantity,
                    'unit_price' => $item['unit_price'],
                    'total_price' => $total,
                    'notes' => $item['notes'],
                ]
            );

            $quotation = $this->quotation ?? QuotationRequest::find($this->quotationId);
            if ($quotation) {
                $service = app(AdditionalServicesSelectionService::class);
                $article = RobawsArticleCache::find($articleId);
                $service->syncAdditionalServicePivot($quotation, $model->fresh(), $article);
                $this->quotation = $quotation->fresh(['additionalServices.article', 'selectedSchedule.carrier', 'commodityItems']);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to persist additional service selection', [
                'quotation_id' => $this->quotationId,
                'article_id' => $articleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function recalculateTotals(): void
    {
        $categoryTotals = [];
        $grandTotal = 0;

        foreach ($this->serviceItems as $articleId => $item) {
            if (!($this->selected[$articleId] ?? false)) {
                continue;
            }

            $quantity = $this->quantities[$articleId] ?? 0;
            $lineTotal = $item['unit_price'] * $quantity;

            $category = $item['category'];
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $lineTotal;
            $grandTotal += $lineTotal;
        }

        $this->totals = [
            'categories' => $categoryTotals,
            'grand' => $grandTotal,
        ];
    }

    protected function groupItemsByCategory(): array
    {
        $grouped = [];

        foreach ($this->serviceItems as $articleId => $item) {
            $category = $item['category'];
            $isSelected = $this->selected[$articleId] ?? false;
            $quantity = $this->quantities[$articleId] ?? 0;
            $unitPrice = $item['unit_price'] ?? 0;

            $grouped[$category][] = array_merge($item, [
                'article_id' => $articleId,
                'is_selected' => $isSelected,
                'quantity' => $quantity,
                'total_price' => $isSelected ? ($quantity * $unitPrice) : 0,
            ]);
        }

        ksort($grouped);

        return $grouped;
    }
}

