<?php

namespace App\Filament\Pages;

use App\Models\CarrierPurchaseTariff;
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
    
    public ?string $editingKey = null;
    
    // Whitelist of editable amount fields
    protected array $editableFields = [
        'base_freight_amount',
        'baf_amount',
        'ets_amount',
        'port_additional_amount',
        'admin_fxe_amount',
        'thc_amount',
        'measurement_costs_amount',
        'congestion_surcharge_amount',
        'iccm_amount',
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
        $this->editingKey = null;
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
                    foreach ($payload as $field => $value) {
                        // Only allow whitelist fields
                        if (!in_array($field, $this->editableFields)) {
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
}

