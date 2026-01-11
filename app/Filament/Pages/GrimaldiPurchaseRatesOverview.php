<?php

namespace App\Filament\Pages;

use App\Models\CarrierPurchaseTariff;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use App\Models\Port;
use App\Services\Pricing\GrimaldiPurchaseRatesOverviewService;
use App\Services\Export\Clients\RobawsApiClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

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
    
    // Date management properties
    public ?string $bulkUpdateDate = null;
    public ?string $bulkValidityDate = null;
    public array $portDates = []; // [portCode => ['update_date' => ..., 'validity_date' => ...]]
    
    // Modal state properties
    public ?string $createMappingPortCode = null;
    public ?string $createMappingCategory = null;
    public ?int $editingMappingId = null;
    
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
        
        // Initialize portDates with oldest dates from article cache
        $this->portDates = [];
        foreach ($this->matrix['ports'] ?? [] as $portCode => $portData) {
            $oldestUpdateDate = $portData['oldest_update_date'] ?? null;
            $oldestValidityDate = $portData['oldest_validity_date'] ?? null;
            
            if ($oldestUpdateDate || $oldestValidityDate) {
                $this->portDates[$portCode] = [
                    'update_date' => $oldestUpdateDate ? (is_object($oldestUpdateDate) ? $oldestUpdateDate->format('Y-m-d') : $oldestUpdateDate) : null,
                    'validity_date' => $oldestValidityDate ? (is_object($oldestValidityDate) ? $oldestValidityDate->format('Y-m-d') : $oldestValidityDate) : null,
                ];
            }
        }
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
                'update_date' => $tariff->update_date?->format('Y-m-d'),
                'validity_date' => $tariff->validity_date?->format('Y-m-d'),
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
                if (isset($details['update_date'])) {
                    $validated['update_date'] = $details['update_date'] ?: null; // Allow clearing
                }
                if (isset($details['validity_date'])) {
                    $validated['validity_date'] = $details['validity_date'] ?: null; // Allow clearing
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
                
                // Sync dates to article cache
                $this->syncTariffDatesToArticle($tariff);
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
    
    /**
     * Apply bulk dates to all destinations
     */
    public function applyBulkDates(): void
    {
        if (!$this->bulkUpdateDate && !$this->bulkValidityDate) {
            return;
        }
        
        try {
            $bulkUpdateDate = $this->bulkUpdateDate;
            $bulkValidityDate = $this->bulkValidityDate;
            $syncService = app(\App\Services\Pricing\TariffDateSyncService::class);
            
            $carrier = ShippingCarrier::where('code', 'GRIMALDI')->firstOrFail();
            
            CarrierPurchaseTariff::query()
                ->whereHas('carrierArticleMapping', function ($q) use ($carrier) {
                    $q->where('carrier_id', $carrier->id);
                })
                ->with(['carrierArticleMapping.article'])
                ->chunkById(200, function ($tariffs) use ($bulkUpdateDate, $bulkValidityDate, $syncService) {
                    // No transaction - Laravel writes are atomic per statement
                    foreach ($tariffs as $tariff) {
                        if ($bulkUpdateDate) {
                            $tariff->update_date = $bulkUpdateDate;
                        }
                        if ($bulkValidityDate) {
                            $tariff->validity_date = $bulkValidityDate;
                        }
                        $tariff->save();
                        
                        $syncService->syncTariffDatesToArticle($tariff);
                    }
                });
            
            $this->loadMatrix();
            Notification::make()->success()->title('Bulk dates applied')->send();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
        }
    }
    
    /**
     * Apply dates to a specific port
     */
    public function applyPortDates(string $portCode): void
    {
        $portDates = $this->portDates[$portCode] ?? [];
        
        if (empty($portDates['update_date']) && empty($portDates['validity_date'])) {
            Notification::make()->warning()->title('No dates provided')->body('Please enter at least one date before applying.')->send();
            return;
        }
        
        try {
            $updateDate = !empty($portDates['update_date']) ? $portDates['update_date'] : null;
            $validityDate = !empty($portDates['validity_date']) ? $portDates['validity_date'] : null;
            $syncService = app(\App\Services\Pricing\TariffDateSyncService::class);
            
            $carrier = ShippingCarrier::where('code', 'GRIMALDI')->firstOrFail();
            $port = Port::where('code', $portCode)->first();
            
            if (!$port) {
                throw new \Exception("Port {$portCode} not found");
            }
            
            // Track article codes that were updated for pushing to Robaws
            $updatedArticleCodes = [];
            
            // Filter by article POD (matches how service groups ports)
            CarrierPurchaseTariff::query()
                ->whereHas('carrierArticleMapping', function ($q) use ($carrier, $port) {
                    $q->where('carrier_id', $carrier->id)
                      ->whereHas('article', function ($articleQ) use ($port) {
                          // Primary: use normalized pod_code field
                          $articleQ->where('pod_code', $port->code);
                      });
                })
                ->with(['carrierArticleMapping.article'])
                ->chunkById(200, function ($tariffs) use ($updateDate, $validityDate, $syncService, &$updatedArticleCodes) {
                    // No transaction
                    foreach ($tariffs as $tariff) {
                        if ($updateDate !== null) {
                            $tariff->update_date = $updateDate ?: null; // Allow clearing
                        }
                        if ($validityDate !== null) {
                            $tariff->validity_date = $validityDate ?: null; // Allow clearing
                        }
                        
                        try {
                            $tariff->save();
                        } catch (\Exception $e) {
                            throw $e;
                        }
                        
                        $syncService->syncTariffDatesToArticle($tariff);
                        
                        // Collect article codes for pushing to Robaws
                        if ($tariff->carrierArticleMapping && $tariff->carrierArticleMapping->article) {
                            $articleCode = $tariff->carrierArticleMapping->article->article_code;
                            if ($articleCode && !in_array($articleCode, $updatedArticleCodes)) {
                                $updatedArticleCodes[] = $articleCode;
                            }
                        }
                    }
                });
            
            // Push updated articles to Robaws
            if (!empty($updatedArticleCodes)) {
                try {
                    // Push each article to Robaws
                    $client = app(RobawsApiClient::class);
                    $pushedCount = 0;
                    $failedCount = 0;
                    
                    foreach ($updatedArticleCodes as $articleCode) {
                        $article = RobawsArticleCache::where('article_code', $articleCode)->first();
                        if (!$article || !$article->robaws_article_id) {
                            continue;
                        }
                        
                        $updateDate = $article->effective_update_date?->format('m/d/Y');
                        $validityDate = $article->effective_validity_date?->format('m/d/Y');
                        
                        if (!$updateDate && !$validityDate) {
                            continue;
                        }
                        
                        $extraFields = [];
                        if ($updateDate) {
                            $extraFields['UPDATE DATE'] = [
                                'type' => 'TEXT',
                                'group' => 'IMPORTANT INFO',
                                'stringValue' => $updateDate,
                            ];
                        }
                        if ($validityDate) {
                            $extraFields['VALIDITY DATE'] = [
                                'type' => 'TEXT',
                                'group' => 'IMPORTANT INFO',
                                'stringValue' => $validityDate,
                            ];
                        }
                        
                        $payload = ['extraFields' => $extraFields];
                        $response = $client->updateArticle($article->robaws_article_id, $payload);
                        
                        if ($response['success'] ?? false) {
                            $pushedCount++;
                            // Update last_pushed timestamps
                            $article->update([
                                'last_pushed_dates_at' => now(),
                                'last_pushed_update_date' => $article->effective_update_date,
                                'last_pushed_validity_date' => $article->effective_validity_date,
                            ]);
                        } else {
                            $failedCount++;
                        }
                        
                        // Small delay to respect rate limits
                        usleep(100000); // 100ms
                    }
                    
                    if ($pushedCount > 0) {
                        Notification::make()
                            ->success()
                            ->title("Dates applied to {$portCode}")
                            ->body("Pushed {$pushedCount} article(s) to Robaws" . ($failedCount > 0 ? " ({$failedCount} failed)" : ""))
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->warning()
                        ->title("Dates applied to {$portCode}")
                        ->body("Failed to push to Robaws: " . $e->getMessage())
                        ->send();
                }
            } else {
                Notification::make()->success()->title("Dates applied to {$portCode}")->send();
            }
            
            $this->loadMatrix();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
        }
    }
    
    /**
     * Clear date overrides for a specific port
     */
    public function clearPortDates(string $portCode): void
    {
        try {
            $syncService = app(\App\Services\Pricing\TariffDateSyncService::class);
            $carrier = ShippingCarrier::where('code', 'GRIMALDI')->firstOrFail();
            $port = Port::where('code', $portCode)->first();
            
            if (!$port) {
                throw new \Exception("Port {$portCode} not found");
            }
            
            CarrierPurchaseTariff::query()
                ->whereHas('carrierArticleMapping', function ($q) use ($carrier, $port) {
                    $q->where('carrier_id', $carrier->id)
                      ->whereHas('article', function ($articleQ) use ($port) {
                          $articleQ->where('pod_code', $port->code);
                      });
                })
                ->with(['carrierArticleMapping.article'])
                ->chunkById(200, function ($tariffs) use ($syncService) {
                    foreach ($tariffs as $tariff) {
                        // Clear dates on tariff
                        $tariff->update_date = null;
                        $tariff->validity_date = null;
                        $tariff->save();
                        
                        // This will clear overrides via sync service
                        $syncService->syncTariffDatesToArticle($tariff);
                    }
                });
            
            $this->loadMatrix();
            Notification::make()->success()->title("Date overrides cleared for {$portCode}")->send();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
        }
    }
    
    /**
     * Clear all date overrides
     */
    public function clearBulkDates(): void
    {
        try {
            $syncService = app(\App\Services\Pricing\TariffDateSyncService::class);
            $carrier = ShippingCarrier::where('code', 'GRIMALDI')->firstOrFail();
            
            CarrierPurchaseTariff::query()
                ->whereHas('carrierArticleMapping', function ($q) use ($carrier) {
                    $q->where('carrier_id', $carrier->id);
                })
                ->with(['carrierArticleMapping.article'])
                ->chunkById(200, function ($tariffs) use ($syncService) {
                    foreach ($tariffs as $tariff) {
                        $tariff->update_date = null;
                        $tariff->validity_date = null;
                        $tariff->save();
                        
                        $syncService->syncTariffDatesToArticle($tariff);
                    }
                });
            
            $this->loadMatrix();
            Notification::make()->success()->title('All date overrides cleared')->send();
        } catch (\Exception $e) {
            Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
        }
    }
    
    /**
     * Sync tariff dates to article cache (uses service)
     */
    protected function syncTariffDatesToArticle(CarrierPurchaseTariff $tariff): void
    {
        app(\App\Services\Pricing\TariffDateSyncService::class)
            ->syncTariffDatesToArticle($tariff);
    }
    
    /**
     * Open the create mapping modal with pre-filled port and category
     */
    public function openCreateMappingModal(string $portCode, string $category): void
    {
        $this->createMappingPortCode = $portCode;
        $this->createMappingCategory = $category;
        $this->mountAction('createMapping');
    }
    
    /**
     * Open the edit mapping modal with existing mapping data
     */
    public function openEditMappingModal(int $mappingId): void
    {
        $this->editingMappingId = $mappingId;
        $this->mountAction('editMapping');
    }
    
    /**
     * Create a new carrier article mapping
     */
    public function createMapping(array $data): void
    {
        try {
            // Get Grimaldi carrier
            $carrier = ShippingCarrier::where('code', 'GRIMALDI')
                ->orWhereRaw('LOWER(name) LIKE ?', ['%grimaldi%'])
                ->firstOrFail();
            
            // Pre-fill port_ids from port_code
            if ($this->createMappingPortCode) {
                $port = Port::where('code', $this->createMappingPortCode)->first();
                if ($port) {
                    $data['port_ids'] = [$port->id];
                }
            }
            
            // Pre-fill category_group_ids from category
            if ($this->createMappingCategory) {
                $categoryGroupCode = match($this->createMappingCategory) {
                    'CAR' => 'CARS',
                    'SVAN' => 'SMALL_VANS',
                    'BVAN' => 'BIG_VANS',
                    'LM' => 'LM_CARGO_TRUCKS',
                    default => null,
                };
                
                if ($categoryGroupCode) {
                    $categoryGroup = \App\Models\CarrierCategoryGroup::where('carrier_id', $carrier->id)
                        ->where('code', $categoryGroupCode)
                        ->first();
                    if ($categoryGroup) {
                        $data['category_group_ids'] = [$categoryGroup->id];
                    }
                }
            }
            
            // Set carrier_id and defaults
            $data['carrier_id'] = $carrier->id;
            $data['is_active'] = $data['is_active'] ?? true;
            $data['sort_order'] = 0;
            
            // Normalize empty arrays to null
            foreach (['port_ids', 'port_group_ids', 'vehicle_categories', 'category_group_ids', 'vessel_names', 'vessel_classes'] as $field) {
                if (isset($data[$field]) && empty($data[$field])) {
                    $data[$field] = null;
                }
            }
            
            // Category group vs vehicle category exclusivity
            if (!empty($data['category_group_ids'])) {
                $data['vehicle_categories'] = null;
            }
            if (!empty($data['vehicle_categories'])) {
                $data['category_group_ids'] = null;
            }
            
            // Create the mapping
            \App\Models\CarrierArticleMapping::create($data);
            
            // Refresh matrix data
            $this->loadMatrix();
            
            Notification::make()
                ->title('Mapping Created')
                ->body('The freight mapping has been created successfully.')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            \Log::error('Failed to create mapping', [
                'port_code' => $this->createMappingPortCode,
                'category' => $this->createMappingCategory,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            Notification::make()
                ->title('Error')
                ->body('Failed to create mapping: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    /**
     * Update an existing carrier article mapping
     */
    public function updateMapping(array $data): void
    {
        try {
            if (!$this->editingMappingId) {
                throw new \Exception('No mapping ID provided for update');
            }
            
            // Load existing mapping
            $mapping = \App\Models\CarrierArticleMapping::findOrFail($this->editingMappingId);
            
            // Normalize empty arrays to null
            foreach (['port_ids', 'port_group_ids', 'vehicle_categories', 'category_group_ids', 'vessel_names', 'vessel_classes'] as $field) {
                if (isset($data[$field]) && empty($data[$field])) {
                    $data[$field] = null;
                }
            }
            
            // Category group vs vehicle category exclusivity
            if (!empty($data['category_group_ids'])) {
                $data['vehicle_categories'] = null;
            }
            if (!empty($data['vehicle_categories'])) {
                $data['category_group_ids'] = null;
            }
            
            // Update the mapping
            $mapping->update($data);
            
            // Refresh matrix data
            $this->loadMatrix();
            
            Notification::make()
                ->title('Mapping Updated')
                ->body('The freight mapping has been updated successfully.')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('Failed to update mapping: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
    
    /**
     * Get actions (for modal)
     */
    protected function getActions(): array
    {
        return [
            \Filament\Actions\Action::make('createMapping')
                ->label('Create Mapping')
                ->modalHeading('Create Freight Mapping')
                ->modalSubmitActionLabel('Create')
                ->form($this->getCreateMappingFormSchema())
                ->action(function (array $data) {
                    $this->createMapping($data);
                })
                ->after(function () {
                    $this->createMappingPortCode = null;
                    $this->createMappingCategory = null;
                }),
            \Filament\Actions\Action::make('editMapping')
                ->label('Edit Mapping')
                ->modalHeading('Edit Freight Mapping')
                ->modalSubmitActionLabel('Update')
                ->form($this->getEditMappingFormSchema())
                ->fillForm(function () {
                    if (!$this->editingMappingId) {
                        return [];
                    }
                    
                    $mapping = \App\Models\CarrierArticleMapping::find($this->editingMappingId);
                    if (!$mapping) {
                        return [];
                    }
                    
                    return [
                        'article_id' => $mapping->article_id,
                        'name' => $mapping->name,
                        'port_ids' => $mapping->port_ids,
                        'port_group_ids' => $mapping->port_group_ids,
                        'vehicle_categories' => $mapping->vehicle_categories,
                        'category_group_ids' => $mapping->category_group_ids,
                        'vessel_names' => $mapping->vessel_names,
                        'vessel_classes' => $mapping->vessel_classes,
                        'is_active' => $mapping->is_active,
                    ];
                })
                ->action(function (array $data) {
                    $this->updateMapping($data);
                })
                ->after(function () {
                    $this->editingMappingId = null;
                }),
        ];
    }
    
    /**
     * Get form schema for creating mapping
     */
    protected function getCreateMappingFormSchema(): array
    {
        // Get Grimaldi carrier for category groups
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%grimaldi%'])
            ->first();
        $carrierId = $carrier?->id;
        
        return [
            \Filament\Forms\Components\Select::make('article_id')
                ->label('Article')
                ->options(function () {
                    return \App\Models\RobawsArticleCache::where('is_parent_item', true)
                        ->where('is_active', true)
                        ->orderBy('article_name')
                        ->get()
                        ->mapWithKeys(function ($article) {
                            return [$article->id => $article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')'];
                        })
                        ->toArray();
                })
                ->searchable()
                ->getSearchResultsUsing(function (string $search) {
                    return \App\Models\RobawsArticleCache::where('is_parent_item', true)
                        ->where('is_active', true)
                        ->where(function ($query) use ($search) {
                            $query->whereRaw('LOWER(article_name) LIKE ?', ['%' . strtolower($search) . '%'])
                                  ->orWhereRaw('LOWER(article_code) LIKE ?', ['%' . strtolower($search) . '%']);
                        })
                        ->orderBy('article_name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(function ($article) {
                            return [$article->id => $article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')'];
                        })
                        ->toArray();
                })
                ->getOptionLabelUsing(function ($value) {
                    if (!$value) {
                        return null;
                    }
                    try {
                        $article = \App\Models\RobawsArticleCache::find($value);
                        return $article ? ($article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')') : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                })
                ->required()
                ->reactive()
                ->afterStateUpdated(function (\Filament\Forms\Set $set, $state, \Filament\Forms\Get $get) {
                    if (!empty($state)) {
                        try {
                            $article = \App\Models\RobawsArticleCache::find($state);
                            if ($article) {
                                $articleName = $article->article_name ?? '';
                                $shortName = explode(',', $articleName)[0];
                                $shortName = strlen($shortName) > 40 ? substr($shortName, 0, 40) . '...' : $shortName;
                                $currentName = $get('name');
                                if (empty($currentName) || $currentName === 'Freight Mapping') {
                                    $set('name', $shortName . ' Mapping');
                                }
                            }
                        } catch (\Throwable $e) {
                            // Silent fail
                        }
                    }
                })
                ->helperText('Select the article to map. Only active parent articles are shown.')
                ->columnSpanFull(),
            
            \Filament\Forms\Components\TextInput::make('name')
                ->label('Mapping Name')
                ->maxLength(255)
                ->helperText('Optional: Give this mapping a descriptive name. Auto-populated from article name if left empty.')
                ->columnSpanFull(),
            
            \Filament\Forms\Components\Select::make('port_ids')
                ->label('Ports (POD)')
                ->searchable()
                ->getSearchResultsUsing(function (string $search) {
                    $searchLower = strtolower($search);
                    $ports = \App\Models\Port::where(function($q) use ($searchLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                          ->orWhereRaw('LOWER(code) LIKE ?', ["%{$searchLower}%"]);
                    })
                    ->orWhereHas('aliases', function($q) use ($searchLower) {
                        $q->where('alias_normalized', 'LIKE', "%{$searchLower}%")
                          ->where('is_active', true);
                    })
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->unique('id')
                    ->mapWithKeys(function ($port) {
                        return [$port->id => $port->formatFull()];
                    });
                    
                    return $ports->all();
                })
                ->getOptionLabelsUsing(function (array $values): array {
                    if (empty($values)) {
                        return [];
                    }
                    return \App\Models\Port::whereIn('id', $values)
                        ->get()
                        ->mapWithKeys(function ($port) {
                            return [$port->id => $port->formatFull()];
                        })
                        ->toArray();
                })
                ->multiple()
                ->helperText('Select one or more ports. Leave empty for global rule. Search includes aliases.')
                ->default(function () {
                    if ($this->createMappingPortCode) {
                        $port = Port::where('code', $this->createMappingPortCode)->first();
                        return $port ? [$port->id] : [];
                    }
                    return [];
                })
                ->columnSpan(1),
            
            \Filament\Forms\Components\Select::make('port_group_ids')
                ->label('Port Groups')
                ->options(function () use ($carrierId) {
                    if (!$carrierId) {
                        return [];
                    }
                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                        ->active()
                        ->orderBy('sort_order')
                        ->pluck('display_name', 'id')
                        ->toArray();
                })
                ->multiple()
                ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\Select::make('vehicle_categories')
                ->label('Vehicle Categories')
                ->options(function () {
                    return config('quotation.commodity_types.vehicles.categories', []);
                })
                ->searchable()
                ->multiple()
                ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                ->columnSpan(1)
                ->disabled(fn (\Filament\Forms\Get $get) => !empty($get('category_group_ids')))
                ->live(),
            
            \Filament\Forms\Components\Select::make('category_group_ids')
                ->label('Category Groups')
                ->options(function () use ($carrierId) {
                    if (!$carrierId) {
                        return [];
                    }
                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                        ->where('is_active', true)
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->toArray();
                })
                ->multiple()
                ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                ->default(function () use ($carrierId) {
                    if ($this->createMappingCategory && $carrierId) {
                        $categoryGroupCode = match($this->createMappingCategory) {
                            'CAR' => 'CARS',
                            'SVAN' => 'SMALL_VANS',
                            'BVAN' => 'BIG_VANS',
                            'LM' => 'LM_CARGO_TRUCKS',
                            default => null,
                        };
                        if ($categoryGroupCode) {
                            $categoryGroup = \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                                ->where('code', $categoryGroupCode)
                                ->first();
                            return $categoryGroup ? [$categoryGroup->id] : [];
                        }
                    }
                    return [];
                })
                ->columnSpan(1)
                ->disabled(fn (\Filament\Forms\Get $get) => !empty($get('vehicle_categories')))
                ->live(),
            
            \Filament\Forms\Components\TagsInput::make('vessel_names')
                ->label('Vessel Names')
                ->helperText('Enter vessel names (one per line or comma-separated). Leave empty for all vessels.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\TagsInput::make('vessel_classes')
                ->label('Vessel Classes')
                ->helperText('Enter vessel classes (one per line or comma-separated). Leave empty for all vessel classes.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->columnSpan(1),
        ];
    }
    
    /**
     * Get form schema for editing mapping (reuses create form schema)
     */
    protected function getEditMappingFormSchema(): array
    {
        // Get Grimaldi carrier for category groups
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%grimaldi%'])
            ->first();
        $carrierId = $carrier?->id;
        
        return [
            \Filament\Forms\Components\Select::make('article_id')
                ->label('Article')
                ->options(function () {
                    return \App\Models\RobawsArticleCache::where('is_parent_item', true)
                        ->where('is_active', true)
                        ->orderBy('article_name')
                        ->get()
                        ->mapWithKeys(function ($article) {
                            return [$article->id => $article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')'];
                        })
                        ->toArray();
                })
                ->searchable()
                ->getSearchResultsUsing(function (string $search) {
                    return \App\Models\RobawsArticleCache::where('is_parent_item', true)
                        ->where('is_active', true)
                        ->where(function ($query) use ($search) {
                            $query->whereRaw('LOWER(article_name) LIKE ?', ['%' . strtolower($search) . '%'])
                                  ->orWhereRaw('LOWER(article_code) LIKE ?', ['%' . strtolower($search) . '%']);
                        })
                        ->orderBy('article_name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(function ($article) {
                            return [$article->id => $article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')'];
                        })
                        ->toArray();
                })
                ->getOptionLabelUsing(function ($value) {
                    if (!$value) {
                        return null;
                    }
                    try {
                        $article = \App\Models\RobawsArticleCache::find($value);
                        return $article ? ($article->article_name . ' (' . ($article->article_code ?? 'N/A') . ')') : null;
                    } catch (\Throwable $e) {
                        return null;
                    }
                })
                ->required()
                ->reactive()
                ->afterStateUpdated(function (\Filament\Forms\Set $set, $state, \Filament\Forms\Get $get) {
                    if (!empty($state)) {
                        try {
                            $article = \App\Models\RobawsArticleCache::find($state);
                            if ($article) {
                                $articleName = $article->article_name ?? '';
                                $shortName = explode(',', $articleName)[0];
                                $shortName = strlen($shortName) > 40 ? substr($shortName, 0, 40) . '...' : $shortName;
                                $currentName = $get('name');
                                if (empty($currentName) || $currentName === 'Freight Mapping') {
                                    $set('name', $shortName . ' Mapping');
                                }
                            }
                        } catch (\Throwable $e) {
                            // Silent fail
                        }
                    }
                })
                ->helperText('Select the article to map. Only active parent articles are shown.')
                ->columnSpanFull(),
            
            \Filament\Forms\Components\TextInput::make('name')
                ->label('Mapping Name')
                ->maxLength(255)
                ->helperText('Optional: Give this mapping a descriptive name. Auto-populated from article name if left empty.')
                ->columnSpanFull(),
            
            \Filament\Forms\Components\Select::make('port_ids')
                ->label('Ports (POD)')
                ->searchable()
                ->getSearchResultsUsing(function (string $search) {
                    $searchLower = strtolower($search);
                    $ports = \App\Models\Port::where(function($q) use ($searchLower) {
                        $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                          ->orWhereRaw('LOWER(code) LIKE ?', ["%{$searchLower}%"]);
                    })
                    ->orWhereHas('aliases', function($q) use ($searchLower) {
                        $q->where('alias_normalized', 'LIKE', "%{$searchLower}%")
                          ->where('is_active', true);
                    })
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->unique('id')
                    ->mapWithKeys(function ($port) {
                        return [$port->id => $port->formatFull()];
                    });
                    
                    return $ports->all();
                })
                ->getOptionLabelsUsing(function (array $values): array {
                    if (empty($values)) {
                        return [];
                    }
                    return \App\Models\Port::whereIn('id', $values)
                        ->get()
                        ->mapWithKeys(function ($port) {
                            return [$port->id => $port->formatFull()];
                        })
                        ->toArray();
                })
                ->multiple()
                ->helperText('Select one or more ports. Leave empty for global rule. Search includes aliases.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\Select::make('port_group_ids')
                ->label('Port Groups')
                ->options(function () use ($carrierId) {
                    if (!$carrierId) {
                        return [];
                    }
                    return \App\Models\CarrierPortGroup::where('carrier_id', $carrierId)
                        ->active()
                        ->orderBy('sort_order')
                        ->pluck('display_name', 'id')
                        ->toArray();
                })
                ->multiple()
                ->helperText('Select port groups (e.g., WAF, MED). If both Ports and Port Groups are set, rule matches if either matches.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\Select::make('vehicle_categories')
                ->label('Vehicle Categories')
                ->options(function () {
                    return config('quotation.commodity_types.vehicles.categories', []);
                })
                ->searchable()
                ->multiple()
                ->helperText('Select one or more vehicle categories. Leave empty for all categories. Note: If Category Group is selected, this field should be empty.')
                ->columnSpan(1)
                ->disabled(fn (\Filament\Forms\Get $get) => !empty($get('category_group_ids')))
                ->live(),
            
            \Filament\Forms\Components\Select::make('category_group_ids')
                ->label('Category Groups')
                ->options(function () use ($carrierId) {
                    if (!$carrierId) {
                        return [];
                    }
                    return \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                        ->where('is_active', true)
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->toArray();
                })
                ->multiple()
                ->helperText('OR use one or more category groups. Note: If Vehicle Categories are selected, this field should be empty.')
                ->columnSpan(1)
                ->disabled(fn (\Filament\Forms\Get $get) => !empty($get('vehicle_categories')))
                ->live(),
            
            \Filament\Forms\Components\TagsInput::make('vessel_names')
                ->label('Vessel Names')
                ->helperText('Enter vessel names (one per line or comma-separated). Leave empty for all vessels.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\TagsInput::make('vessel_classes')
                ->label('Vessel Classes')
                ->helperText('Enter vessel classes (one per line or comma-separated). Leave empty for all vessel classes.')
                ->columnSpan(1),
            
            \Filament\Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->columnSpan(1),
        ];
    }
}

