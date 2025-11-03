<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;

class CommodityItemsRepeater extends Component
{
    use WithFileUploads;

    public $items = [];
    public $unitSystem = 'metric';
    public $serviceType = '';
    public $commodityTypes;
    public $unitSystems;
    
    protected $listeners = ['serviceTypeUpdated' => 'updateServiceType'];
    
    protected $validationAttributes = [
        'items.*.commodity_type' => 'commodity type',
        'items.*.category' => 'category',
        'items.*.make' => 'make',
        'items.*.type_model' => 'type/model',
        'items.*.condition' => 'condition',
        'items.*.year' => 'year',
        'items.*.fuel_type' => 'fuel type',
        'items.*.length_cm' => 'length',
        'items.*.width_cm' => 'width',
        'items.*.height_cm' => 'height',
        'items.*.quantity' => 'quantity',
    ];

    public function mount($existingItems = [], $serviceType = '', $unitSystem = 'metric')
    {
        $this->serviceType = $serviceType;
        $this->unitSystem = $unitSystem;
        $this->commodityTypes = config('quotation.commodity_types');
        $this->unitSystems = config('quotation.unit_systems');
        
        // Only initialize items if they haven't been set yet (mount is only called once)
        // If existingItems is provided, use them; otherwise start with empty array
        if (!empty($existingItems)) {
            $this->items = $existingItems;
        } elseif (empty($this->items)) {
            // Only set to empty array if items is not already set
            $this->items = [];
        }
    }

    public function addItem()
    {
        try {
            \Log::info('CommodityItemsRepeater::addItem() called', [
                'current_items_count' => count($this->items)
            ]);
            
            $this->items[] = [
                'id' => uniqid(),
                'commodity_type' => '',  // This will be used for form display
                'category' => '',
                'make' => '',
                'type_model' => '',
                'condition' => '',
                'year' => '',
                'fuel_type' => '',
                'length_cm' => '',
                'width_cm' => '',
                'height_cm' => '',
                'cbm' => '',
                'weight_kg' => '',
                'bruto_weight_kg' => '',
                'netto_weight_kg' => '',
                'wheelbase_cm' => '',
                'quantity' => 1,
                'has_parts' => false,
                'parts_description' => '',
                'has_trailer' => false,
                'has_wooden_cradle' => false,
                'has_iron_cradle' => false,
                'is_forkliftable' => false,
                'is_hazardous' => false,
                'is_unpacked' => false,
                'is_ispm15' => false,
                'extra_info' => '',
                'attachments' => [],
                'input_unit_system' => $this->unitSystem,
            ];
            
            // Force Livewire to re-render by reassigning the array
            // This ensures Livewire detects the change
            $this->items = array_values($this->items);
            
            \Log::info('CommodityItemsRepeater::addItem() completed', [
                'new_items_count' => count($this->items),
                'last_item_id' => end($this->items)['id'] ?? null
            ]);
        } catch (\Exception $e) {
            \Log::error('CommodityItemsRepeater::addItem() failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function removeItem($index)
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items); // Re-index
    }

    public function calculateCbm($index)
    {
        $item = &$this->items[$index];
        
        $length = floatval($item['length_cm'] ?? 0);
        $width = floatval($item['width_cm'] ?? 0);
        $height = floatval($item['height_cm'] ?? 0);
        
        if ($length > 0 && $width > 0 && $height > 0) {
            if ($this->unitSystem === 'us') {
                // Calculate in cubic feet (dimensions in inches)
                $cuft = ($length / 12) * ($width / 12) * ($height / 12);
                $item['cbm'] = round($cuft, 2);
            } else {
                // Calculate in CBM (dimensions in cm)
                $cbm = ($length / 100) * ($width / 100) * ($height / 100);
                $item['cbm'] = round($cbm, 4);
            }
        } else {
            $item['cbm'] = '';
        }
    }

    public function updatedUnitSystem($value)
    {
        $previousSystem = $this->unitSystem === 'metric' ? 'us' : 'metric';
        
        // Convert all existing values when unit system changes
        foreach ($this->items as $index => &$item) {
            // Convert dimensions (cm ↔ inch)
            if (!empty($item['length_cm'])) {
                $item['length_cm'] = $this->convertLength($item['length_cm'], $previousSystem, $value);
            }
            if (!empty($item['width_cm'])) {
                $item['width_cm'] = $this->convertLength($item['width_cm'], $previousSystem, $value);
            }
            if (!empty($item['height_cm'])) {
                $item['height_cm'] = $this->convertLength($item['height_cm'], $previousSystem, $value);
            }
            if (!empty($item['wheelbase_cm'])) {
                $item['wheelbase_cm'] = $this->convertLength($item['wheelbase_cm'], $previousSystem, $value);
            }
            
            // Convert weights (kg ↔ lbs)
            if (!empty($item['weight_kg'])) {
                $item['weight_kg'] = $this->convertWeight($item['weight_kg'], $previousSystem, $value);
            }
            if (!empty($item['bruto_weight_kg'])) {
                $item['bruto_weight_kg'] = $this->convertWeight($item['bruto_weight_kg'], $previousSystem, $value);
            }
            if (!empty($item['netto_weight_kg'])) {
                $item['netto_weight_kg'] = $this->convertWeight($item['netto_weight_kg'], $previousSystem, $value);
            }
            
            // Recalculate CBM with converted dimensions
            $this->calculateCbm($index);
        }
    }
    
    /**
     * Convert length values between metric (cm) and US (inch)
     */
    private function convertLength($value, $fromSystem, $toSystem)
    {
        if ($fromSystem === 'metric' && $toSystem === 'us') {
            // cm to inch
            return round($value / 2.54, 2);
        } elseif ($fromSystem === 'us' && $toSystem === 'metric') {
            // inch to cm
            return round($value * 2.54, 2);
        }
        return $value;
    }
    
    /**
     * Convert weight values between metric (kg) and US (lbs)
     */
    private function convertWeight($value, $fromSystem, $toSystem)
    {
        if ($fromSystem === 'metric' && $toSystem === 'us') {
            // kg to lbs
            return round($value / 0.453592, 2);
        } elseif ($fromSystem === 'us' && $toSystem === 'metric') {
            // lbs to kg
            return round($value * 0.453592, 2);
        }
        return $value;
    }

    public function updateServiceType($serviceType)
    {
        $this->serviceType = $serviceType;
    }

    public function getItemsForSubmission()
    {
        $processedItems = [];
        
        foreach ($this->items as $index => $item) {
            $processedItem = $item;
            $processedItem['line_number'] = $index + 1;
            
            // Convert to metric if in US system
            if ($this->unitSystem === 'us') {
                $processedItem['length_cm'] = $item['length_cm'] ? round($item['length_cm'] * 2.54, 2) : null;
                $processedItem['width_cm'] = $item['width_cm'] ? round($item['width_cm'] * 2.54, 2) : null;
                $processedItem['height_cm'] = $item['height_cm'] ? round($item['height_cm'] * 2.54, 2) : null;
                $processedItem['weight_kg'] = $item['weight_kg'] ? round($item['weight_kg'] * 0.453592, 2) : null;
                $processedItem['bruto_weight_kg'] = $item['bruto_weight_kg'] ? round($item['bruto_weight_kg'] * 0.453592, 2) : null;
                $processedItem['netto_weight_kg'] = $item['netto_weight_kg'] ? round($item['netto_weight_kg'] * 0.453592, 2) : null;
                $processedItem['wheelbase_cm'] = $item['wheelbase_cm'] ? round($item['wheelbase_cm'] * 2.54, 2) : null;
                
                // Recalculate CBM in metric
                if ($processedItem['length_cm'] && $processedItem['width_cm'] && $processedItem['height_cm']) {
                    $processedItem['cbm'] = ($processedItem['length_cm'] / 100) * 
                                           ($processedItem['width_cm'] / 100) * 
                                           ($processedItem['height_cm'] / 100);
                }
            }
            
            $processedItem['input_unit_system'] = $this->unitSystem;
            $processedItems[] = $processedItem;
        }
        
        return $processedItems;
    }

    public function updated($propertyName)
    {
        // Skip validation for items array changes (when adding/removing items)
        // Only validate when specific item fields are updated
        if (!preg_match('/^items\.(\d+)\./', $propertyName)) {
            return;
        }
        
        // Extract item index from property name
        preg_match('/^items\.(\d+)\./', $propertyName, $matches);
        $itemIndex = $matches[1] ?? null;
        
        // Only validate if the item has a commodity_type set (don't validate empty items)
        if ($itemIndex !== null && isset($this->items[$itemIndex])) {
            $item = $this->items[$itemIndex];
            
            // Skip validation for items without commodity_type (newly added items)
            if (empty($item['commodity_type'])) {
                // Only validate if commodity_type is being set
                if (strpos($propertyName, '.commodity_type') !== false) {
                    $this->validateOnly($propertyName, $this->getValidationRules());
                }
            } else {
                // Item has commodity_type, validate the field
                $this->validateOnly($propertyName, $this->getValidationRules());
            }
        }
        
        // Check if commodity_type was updated for any item and dispatch event
        if (preg_match('/^items\.(\d+)\.commodity_type$/', $propertyName, $matches)) {
            // Check if ANY item has a commodity_type set (not just the one that changed)
            $hasCommodityType = false;
            foreach ($this->items as $item) {
                if (!empty($item['commodity_type'])) {
                    $hasCommodityType = true;
                    break;
                }
            }
            
            // Dispatch event to parent component (QuotationCreator) to update showArticles
            $this->dispatch('commodity-item-type-changed', [
                'has_commodity_type' => $hasCommodityType
            ]);
        }
    }
    
    protected function getValidationRules()
    {
        $rules = [];
        
        foreach ($this->items as $index => $item) {
            $commodityType = $item['commodity_type'] ?? '';
            
            // Base rules for all types
            $rules["items.{$index}.commodity_type"] = 'required';
            $rules["items.{$index}.quantity"] = 'required|integer|min:1';
            
            // Vehicle, Machinery, Boat specific
            if (in_array($commodityType, ['vehicles', 'machinery', 'boat'])) {
                $rules["items.{$index}.make"] = 'required';
                $rules["items.{$index}.type_model"] = 'required';
                $rules["items.{$index}.condition"] = 'required';
                $rules["items.{$index}.year"] = 'required|integer|min:1900|max:2100';
                $rules["items.{$index}.length_cm"] = 'required|numeric|min:1';
                $rules["items.{$index}.width_cm"] = 'required|numeric|min:1';
                $rules["items.{$index}.height_cm"] = 'required|numeric|min:1';
            }
            
            // Vehicle specific
            if ($commodityType === 'vehicles') {
                $rules["items.{$index}.category"] = 'required';
                $rules["items.{$index}.fuel_type"] = 'required';
            }
            
            // Machinery specific
            if ($commodityType === 'machinery') {
                $rules["items.{$index}.category"] = 'required';
                $rules["items.{$index}.fuel_type"] = 'required';
            }
            
            // General Cargo specific
            if ($commodityType === 'general_cargo') {
                $rules["items.{$index}.category"] = 'required';
            }
        }
        
        return $rules;
    }
    
    protected $messages = [
        'items.*.make.required' => 'Make is required',
        'items.*.type_model.required' => 'Type/Model is required',
        'items.*.year.required' => 'Year is required',
        'items.*.year.integer' => 'Year must be a number',
        'items.*.year.min' => 'Year must be at least 1900',
        'items.*.year.max' => 'Year must be at most 2100',
        'items.*.length_cm.required' => 'Length is required',
        'items.*.width_cm.required' => 'Width is required',
        'items.*.height_cm.required' => 'Height is required',
        'items.*.condition.required' => 'Condition is required',
        'items.*.fuel_type.required' => 'Fuel Type is required',
        'items.*.category.required' => 'Category is required',
        'items.*.commodity_type.required' => 'Commodity Type is required',
        'items.*.quantity.required' => 'Quantity is required',
    ];
    
    public function validateItems()
    {
        $this->validate($this->getValidationRules(), $this->messages);
        return true;
    }

    public function render()
    {
        return view('livewire.commodity-items-repeater', [
            'commodityTypes' => $this->commodityTypes,
            'unitSystems' => $this->unitSystems,
        ]);
    }
}
