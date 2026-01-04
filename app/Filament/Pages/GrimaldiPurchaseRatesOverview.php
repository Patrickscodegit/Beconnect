<?php

namespace App\Filament\Pages;

use App\Models\CarrierPurchaseTariff;
use App\Models\RobawsArticleCache;
use App\Services\Pricing\GrimaldiPurchaseRatesOverviewService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class GrimaldiPurchaseRatesOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static string $view = 'filament.pages.grimaldi-purchase-rates-overview';

    protected static ?string $navigationGroup = 'Quotation System';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'Grimaldi Purchase Rates Overview';

    protected static ?string $navigationLabel = 'Grimaldi Purchase Rates';

    public array $matrix = [];
    
    public array $dirty = []; // [tariffId => [field => value]]
    
    public array $dirtySales = []; // [articleId => ['unit_price' => value]]
    
    public ?string $editingKey = null;
    
    public ?int $editingTariffId = null;
    
    public array $tariffDetails = []; // [tariffId => [field => value]]
    
    // Whitelist of editable amount fields
    protected array $editableFields = [
        // Amount fields (existing)
        'base_freight_amount',
        'baf_amount',
        'ets_amount',
        'port_additional_amount',
        'admin_fxe_amount',
        'thc_amount',
        'measurement_costs_amount',
        'congestion_surcharge_amount',
        'iccm_amount',
        // Unit fields (NEW)
        'base_freight_unit',
        'baf_unit',
        'ets_unit',
        'port_additional_unit',
        'admin_fxe_unit',
        'thc_unit',
        'measurement_costs_unit',
        'congestion_surcharge_unit',
        'iccm_unit',
        // Metadata fields (NEW)
        'effective_from',
        'effective_to',
        'currency',
        'is_active',
    ];

    public function mount(): void
    {
        $this->loadMatrix();
    }
    
    public function loadMatrix(): void
    {
        $service = app(GrimaldiPurchaseRatesOverviewService::class);
        $this->matrix = $service->getRatesMatrix();
    }
    
    public function startEditing(int $tariffId, string $field): void
    {
        // Validate field is in whitelist
        if (!in_array($field, $this->editableFields)) {
            return;
        }
        
        $this->editingKey = "{$tariffId}:{$field}";
        
        // Initialize dirty entry if not exists
        if (!isset($this->dirty[$tariffId])) {
            $this->dirty[$tariffId] = [];
        }
        
        // If field not in dirty, initialize with current tariff value
        if (!isset($this->dirty[$tariffId][$field])) {
            // Find the tariff in the matrix
            $tariff = null;
            foreach ($this->matrix['ports'] ?? [] as $portData) {
                foreach ($portData['categories'] ?? [] as $categoryData) {
                    if (($categoryData['tariff_id'] ?? null) === $tariffId) {
                        $tariff = $categoryData['tariff'] ?? null;
                        break 2;
                    }
                }
            }
            
            if ($tariff) {
                $currentValue = $tariff->getAttribute($field);
                $this->dirty[$tariffId][$field] = $currentValue !== null ? (string) $currentValue : '';
            } else {
                $this->dirty[$tariffId][$field] = '';
            }
        }
    }
    
    public function stopEditing(): void
    {
        // Only clear if not editing sales (sales has its own stopEditingSales method)
        if (!str_starts_with($this->editingKey ?? '', 'sales:')) {
            $this->editingKey = null;
        }
    }
    
    public function normalizeNumeric($value): ?float
    {
        if (is_null($value)) {
            return null;
        }
        
        $value = trim((string) $value);
        
        if ($value === '') {
            return null;
        }
        
        // Replace comma with dot for European number format
        $value = str_replace(',', '.', $value);
        
        $floatValue = floatval($value);
        
        // Return null if conversion failed (NaN or invalid)
        if (!is_finite($floatValue)) {
            return null;
        }
        
        return $floatValue;
    }
    
    public function hasDirtyForPort(string $portCode): bool
    {
        if (!isset($this->matrix['ports'][$portCode]['tariff_ids'])) {
            return false;
        }
        
        $tariffIds = $this->matrix['ports'][$portCode]['tariff_ids'];
        
        foreach ($tariffIds as $tariffId) {
            if (isset($this->dirty[$tariffId])) {
                return true;
            }
        }
        
        return false;
    }
    
    public function getDirtyTariffIdsForPort(string $portCode): array
    {
        if (!isset($this->matrix['ports'][$portCode]['tariff_ids'])) {
            return [];
        }
        
        $tariffIds = $this->matrix['ports'][$portCode]['tariff_ids'];
        $dirtyIds = [];
        
        foreach ($tariffIds as $tariffId) {
            if (isset($this->dirty[$tariffId])) {
                $dirtyIds[] = $tariffId;
            }
        }
        
        return $dirtyIds;
    }
    
    public function savePort(string $portCode): void
    {
        $tariffIds = $this->getDirtyTariffIdsForPort($portCode);
        
        if (empty($tariffIds)) {
            return;
        }
        
        try {
            DB::transaction(function () use ($tariffIds) {
                foreach ($tariffIds as $tariffId) {
                    $payload = $this->dirty[$tariffId] ?? [];
                    
                    if (empty($payload)) {
                        continue;
                    }
                    
                    // Validate and normalize each field
                    $validated = [];
                    
                    // Handle amount fields (numeric)
                    foreach ($payload as $field => $value) {
                        // Only allow whitelist fields
                        if (!in_array($field, $this->editableFields)) {
                            continue;
                        }
                        
                        // Skip unit fields (handled separately)
                        if (str_ends_with($field, '_unit')) {
                            continue;
                        }
                        
                        // Skip metadata fields (handled separately)
                        if (in_array($field, ['effective_from', 'effective_to', 'currency', 'is_active', 'source', 'notes'])) {
                            continue;
                        }
                        
                        $normalized = $this->normalizeNumeric($value);
                        
                        // Allow null for optional surcharge fields
                        if ($normalized === null) {
                            // Only allow null for surcharge fields (not base_freight_amount)
                            if ($field !== 'base_freight_amount') {
                                $validated[$field] = null;
                            }
                            continue;
                        }
                        
                        // Validate >= 0
                        if ($normalized < 0) {
                            throw new \InvalidArgumentException("Field {$field} must be >= 0");
                        }
                        
                        $validated[$field] = $normalized;
                    }
                    
                    // Handle unit fields (if present in dirty)
                    foreach (['base_freight_unit', 'baf_unit', 'ets_unit', 'port_additional_unit',
                             'admin_fxe_unit', 'thc_unit', 'measurement_costs_unit',
                             'congestion_surcharge_unit', 'iccm_unit'] as $field) {
                        if (isset($payload[$field])) {
                            $validated[$field] = in_array($payload[$field], ['LUMPSUM', 'LM']) 
                                ? $payload[$field] 
                                : 'LUMPSUM';
                        }
                    }
                    
                    if (empty($validated)) {
                        continue;
                    }
                    
                    $tariff = CarrierPurchaseTariff::query()->findOrFail($tariffId);
                    $tariff->fill($validated);
                    $tariff->save();
                    
                    unset($this->dirty[$tariffId]);
                }
            });
            
            $this->editingKey = null;
            $this->loadMatrix();
            
            Notification::make()
                ->title('Saved')
                ->body("Saved " . count($tariffIds) . " tariff(s) for {$portCode}.")
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to save: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    public function cancelPort(string $portCode): void
    {
        $tariffIds = $this->getDirtyTariffIdsForPort($portCode);
        
        foreach ($tariffIds as $tariffId) {
            unset($this->dirty[$tariffId]);
        }
        
        $this->editingKey = null;
        $this->loadMatrix();
    }
    
    // Sales price editing methods
    
    public function hasDirtySalesForPort(string $portCode): bool
    {
        if (!isset($this->matrix['ports'][$portCode]['categories'])) {
            return false;
        }
        
        foreach ($this->matrix['ports'][$portCode]['categories'] as $categoryData) {
            $articleId = $categoryData['article']['id'] ?? null;
            if ($articleId && isset($this->dirtySales[$articleId])) {
                return true;
            }
        }
        
        return false;
    }
    
    public function getDirtyArticleIdsForPort(string $portCode): array
    {
        if (!isset($this->matrix['ports'][$portCode]['categories'])) {
            return [];
        }
        
        $dirtyIds = [];
        
        foreach ($this->matrix['ports'][$portCode]['categories'] as $categoryData) {
            $articleId = $categoryData['article']['id'] ?? null;
            if ($articleId && isset($this->dirtySales[$articleId])) {
                $dirtyIds[] = $articleId;
            }
        }
        
        return array_unique($dirtyIds);
    }
    
    public function startEditingSales(int $articleId): void
    {
        $this->editingKey = "sales:{$articleId}";
        
        // Initialize dirty entry if not exists
        if (!isset($this->dirtySales[$articleId])) {
            $this->dirtySales[$articleId] = [];
        }
        
        // If unit_price not in dirty, initialize with current article unit_price
        if (!isset($this->dirtySales[$articleId]['unit_price'])) {
            // Find the article in the matrix
            $article = null;
            foreach ($this->matrix['ports'] ?? [] as $portData) {
                foreach ($portData['categories'] ?? [] as $categoryData) {
                    if (($categoryData['article']['id'] ?? null) === $articleId) {
                        $article = $categoryData['article'] ?? null;
                        break 2;
                    }
                }
            }
            
            if ($article) {
                $currentValue = $article['unit_price'] ?? null;
                $this->dirtySales[$articleId]['unit_price'] = $currentValue !== null ? (string) $currentValue : '';
            } else {
                $this->dirtySales[$articleId]['unit_price'] = '';
            }
        }
    }
    
    public function stopEditingSales(): void
    {
        if (str_starts_with($this->editingKey ?? '', 'sales:')) {
            $this->editingKey = null;
        }
    }
    
    public function saveSalesPort(string $portCode): void
    {
        $articleIds = $this->getDirtyArticleIdsForPort($portCode);
        
        if (empty($articleIds)) {
            return;
        }
        
        try {
            DB::transaction(function () use ($articleIds) {
                foreach ($articleIds as $articleId) {
                    $payload = $this->dirtySales[$articleId] ?? [];
                    
                    if (empty($payload)) {
                        continue;
                    }
                    
                    // Validate unit_price
                    $validated = [];
                    if (isset($payload['unit_price'])) {
                        $normalized = $this->normalizeNumeric($payload['unit_price']);
                        // Allow null (to clear price) or >= 0
                        if ($normalized === null || $normalized >= 0) {
                            $validated['unit_price'] = $normalized;
                        }
                    }
                    
                    if (empty($validated)) {
                        continue;
                    }
                    
                    $article = RobawsArticleCache::query()->findOrFail($articleId);
                    $article->fill($validated);
                    $article->save();
                    
                    unset($this->dirtySales[$articleId]);
                }
            });
            
            $this->editingKey = null;
            $this->loadMatrix();
            
            Notification::make()
                ->title('Saved')
                ->body("Saved sales prices for " . count($articleIds) . " article(s) in {$portCode}.")
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to save sales prices: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    public function cancelSalesPort(string $portCode): void
    {
        $articleIds = $this->getDirtyArticleIdsForPort($portCode);
        
        foreach ($articleIds as $articleId) {
            unset($this->dirtySales[$articleId]);
        }
        
        $this->editingKey = null;
        $this->loadMatrix();
    }
    
    // Tariff details editor methods
    
    /**
     * Open tariff details editor modal
     */
    public function openTariffEditor(int $tariffId): void
    {
        $this->editingTariffId = $tariffId;
        
        // Load tariff and populate tariffDetails
        $tariff = CarrierPurchaseTariff::find($tariffId);
        if ($tariff) {
            $this->tariffDetails[$tariffId] = [
                // Amounts
                'base_freight_amount' => $tariff->base_freight_amount,
                'baf_amount' => $tariff->baf_amount,
                'ets_amount' => $tariff->ets_amount,
                'port_additional_amount' => $tariff->port_additional_amount,
                'admin_fxe_amount' => $tariff->admin_fxe_amount,
                'thc_amount' => $tariff->thc_amount,
                'measurement_costs_amount' => $tariff->measurement_costs_amount,
                'congestion_surcharge_amount' => $tariff->congestion_surcharge_amount,
                'iccm_amount' => $tariff->iccm_amount,
                // Units
                'base_freight_unit' => $tariff->base_freight_unit ?? 'LUMPSUM',
                'baf_unit' => $tariff->baf_unit ?? 'LUMPSUM',
                'ets_unit' => $tariff->ets_unit ?? 'LUMPSUM',
                'port_additional_unit' => $tariff->port_additional_unit ?? 'LUMPSUM',
                'admin_fxe_unit' => $tariff->admin_fxe_unit ?? 'LUMPSUM',
                'thc_unit' => $tariff->thc_unit ?? 'LUMPSUM',
                'measurement_costs_unit' => $tariff->measurement_costs_unit ?? 'LUMPSUM',
                'congestion_surcharge_unit' => $tariff->congestion_surcharge_unit ?? 'LUMPSUM',
                'iccm_unit' => $tariff->iccm_unit ?? 'LUMPSUM',
                // Metadata
                'effective_from' => $tariff->effective_from?->format('Y-m-d'),
                'effective_to' => $tariff->effective_to?->format('Y-m-d'),
                'currency' => $tariff->currency ?? 'EUR',
                'is_active' => $tariff->is_active,
                'source' => $tariff->source,
                'notes' => $tariff->notes,
            ];
        }
    }
    
    /**
     * Close tariff editor modal
     */
    public function closeTariffEditor(): void
    {
        $this->editingTariffId = null;
        $this->tariffDetails = [];
    }
    
    /**
     * Save tariff details from modal
     */
    public function saveTariffDetails(): void
    {
        if (!$this->editingTariffId) {
            return;
        }
        
        try {
            DB::transaction(function () {
                $tariff = CarrierPurchaseTariff::findOrFail($this->editingTariffId);
                $details = $this->tariffDetails[$this->editingTariffId] ?? [];
                
                $validated = [];
                
                // Validate and normalize amount fields
                foreach (['base_freight_amount', 'baf_amount', 'ets_amount', 'port_additional_amount',
                         'admin_fxe_amount', 'thc_amount', 'measurement_costs_amount',
                         'congestion_surcharge_amount', 'iccm_amount'] as $field) {
                    if (isset($details[$field])) {
                        $normalized = $this->normalizeNumeric($details[$field]);
                        if ($field === 'base_freight_amount' && $normalized === null) {
                            throw new \InvalidArgumentException("Base freight amount is required");
                        }
                        if ($normalized !== null && $normalized < 0) {
                            throw new \InvalidArgumentException("Field {$field} must be >= 0");
                        }
                        $validated[$field] = $normalized;
                    }
                }
                
                // Unit fields
                foreach (['base_freight_unit', 'baf_unit', 'ets_unit', 'port_additional_unit',
                         'admin_fxe_unit', 'thc_unit', 'measurement_costs_unit',
                         'congestion_surcharge_unit', 'iccm_unit'] as $field) {
                    if (isset($details[$field])) {
                        $validated[$field] = in_array($details[$field], ['LUMPSUM', 'LM']) 
                            ? $details[$field] 
                            : 'LUMPSUM';
                    }
                }
                
                // Date fields
                if (isset($details['effective_from'])) {
                    $validated['effective_from'] = $details['effective_from'] ?: null;
                }
                if (isset($details['effective_to'])) {
                    $validated['effective_to'] = $details['effective_to'] ?: null;
                }
                
                // Other fields
                if (isset($details['currency'])) {
                    $validated['currency'] = in_array($details['currency'], ['EUR', 'USD']) 
                        ? $details['currency'] 
                        : 'EUR';
                }
                if (isset($details['is_active'])) {
                    $validated['is_active'] = (bool) $details['is_active'];
                }
                if (isset($details['source'])) {
                    $validated['source'] = $details['source'];
                }
                if (isset($details['notes'])) {
                    $validated['notes'] = $details['notes'];
                }
                
                $tariff->fill($validated);
                $tariff->save();
            });
            
            $this->closeTariffEditor();
            $this->loadMatrix();
            
            Notification::make()
                ->title('Saved')
                ->body('Tariff details saved successfully.')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to save: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    /**
     * Create new tariff for a mapping
     */
    public function createTariff(int $mappingId): void
    {
        try {
            $mapping = \App\Models\CarrierArticleMapping::findOrFail($mappingId);
            
            // Create default tariff
            $tariff = CarrierPurchaseTariff::create([
                'carrier_article_mapping_id' => $mappingId,
                'effective_from' => now()->format('Y-m-d'),
                'is_active' => true,
                'currency' => 'EUR',
                'base_freight_amount' => 0,
                'base_freight_unit' => 'LUMPSUM',
                'source' => 'manual',
            ]);
            
            $this->openTariffEditor($tariff->id);
            
            Notification::make()
                ->title('Created')
                ->body('New tariff created. Please fill in the details.')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to create tariff: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}

