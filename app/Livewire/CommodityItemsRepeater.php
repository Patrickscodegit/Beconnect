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
    public $quotationId; // ID of the quotation to save items to
    
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

    public $existingItems = []; // Store original existingItems to prevent prop sync from resetting
    
    public function mount($existingItems = [], $serviceType = '', $unitSystem = 'metric', $quotationId = null)
    {
        $this->serviceType = $serviceType;
        $this->unitSystem = $unitSystem;
        $this->quotationId = $quotationId;
        $this->commodityTypes = config('quotation.commodity_types');
        $this->unitSystems = config('quotation.unit_systems');
        
        // Store original existingItems
        $this->existingItems = $existingItems;
        
        // Only initialize items if they haven't been set yet (mount is only called once)
        // If existingItems is provided, use them; otherwise start with empty array
        if (!empty($existingItems)) {
            $this->items = $existingItems;
        } elseif (empty($this->items)) {
            // Only set to empty array if items is not already set
            $this->items = [];
        }
    }
    
    /**
     * Prevent Livewire from syncing existingItems prop if items array has been modified
     * This prevents the parent component from resetting items when it re-renders
     */
    public function updatedExistingItems($value)
    {
        // Only update items if they're currently empty and new value is provided
        // This prevents parent re-renders from overwriting items that were added
        if (empty($this->items) && !empty($value)) {
            $this->items = $value;
        }
        // Otherwise, ignore the prop update to preserve user-added items
    }

    public function addItem($relationshipType = 'separate', $relatedItemId = null)
    {
        try {
            \Log::info('CommodityItemsRepeater::addItem() called', [
                'current_items_count' => count($this->items),
                'quotation_id' => $this->quotationId,
                'relationship_type' => $relationshipType,
                'related_item_id' => $relatedItemId
            ]);
            
            // Don't create database record yet - commodity_type is required (NOT NULL)
            // We'll create it when the user selects a commodity_type
            $tempId = uniqid('temp_');
            
            $this->items[] = [
                'id' => $tempId, // Temporary ID until commodity_type is selected
                'relationship_type' => $relationshipType, // Set from parameter
                'related_item_id' => $relatedItemId, // Set from parameter
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
                'lm' => '',
                'weight_kg' => '',
                'stack_length_cm' => '',
                'stack_width_cm' => '',
                'stack_height_cm' => '',
                'stack_weight_kg' => '',
                'stack_cbm' => '',
                'stack_lm' => '',
                'stack_unit_count' => 0,
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
        if (isset($this->items[$index])) {
            $item = $this->items[$index];
            
            // Delete from database if it has a database ID (not a temporary ID)
            if (isset($item['id']) && is_numeric($item['id']) && $this->quotationId) {
                try {
                    \App\Models\QuotationCommodityItem::where('id', $item['id'])
                        ->where('quotation_request_id', $this->quotationId)
                        ->delete();
                    
                    // Touch parent quotation to update updated_at timestamp
                    // This ensures cache keys change when commodity items are removed
                    \App\Models\QuotationRequest::where('id', $this->quotationId)->touch();
                    
                    // Dispatch event to parent component when item is removed
                    // This triggers refresh of SmartArticleSelector
                    $this->dispatch('commodity-item-saved', [
                        'quotation_id' => $this->quotationId
                    ]);
                    
                    \Log::info('CommodityItemsRepeater::removeItem() deleted from database', [
                        'item_id' => $item['id'],
                        'quotation_id' => $this->quotationId
                    ]);
                } catch (\Exception $e) {
                    \Log::error('CommodityItemsRepeater::removeItem() failed to delete from database', [
                        'item_id' => $item['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            unset($this->items[$index]);
            $this->items = array_values($this->items); // Re-index
            
            // Update line numbers for remaining items
            $this->updateLineNumbers();
        }
    }
    
    /**
     * Update line numbers for all items in database
     */
    protected function updateLineNumbers()
    {
        if (!$this->quotationId) {
            return;
        }
        
        foreach ($this->items as $index => $item) {
            if (isset($item['id']) && is_numeric($item['id'])) {
                try {
                    \App\Models\QuotationCommodityItem::where('id', $item['id'])
                        ->where('quotation_request_id', $this->quotationId)
                        ->update(['line_number' => $index + 1]);
                } catch (\Exception $e) {
                    \Log::error('CommodityItemsRepeater::updateLineNumbers() failed', [
                        'item_id' => $item['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
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
        
        // Also calculate LM when dimensions change
        $this->calculateLm($index);
    }

    public function calculateLm($index)
    {
        $item = &$this->items[$index];
        
        $length = floatval($item['length_cm'] ?? 0);
        $width = floatval($item['width_cm'] ?? 0);
        
        if ($length > 0 && $width > 0) {
            if ($this->unitSystem === 'us') {
                // Calculate LM from inches: (length_in / 12 × width_in / 12) / 2.5
                // Convert 250 cm minimum to inches: 250 / 2.54 = 98.425 inches
                $lengthM = $length / 12 / 0.3048; // Convert inches to meters
                $widthInches = max($width, 250 / 2.54); // Minimum width of 250 cm (98.425 inches)
                $widthM = $widthInches / 12 / 0.3048;
                $lm = ($lengthM * $widthM) / 2.5;
                $item['lm'] = round($lm, 4);
            } else {
                // Calculate LM from cm: (length_cm / 100 × max(width_cm, 250) / 100) / 2.5
                // Width has a minimum of 250 cm (2.5m) for LM calculations
                $lengthM = $length / 100;
                $widthCm = max($width, 250); // Minimum width of 250 cm
                $widthM = $widthCm / 100;
                $lm = ($lengthM * $widthM) / 2.5;
                $item['lm'] = round($lm, 4);
            }
        } else {
            $item['lm'] = '';
        }
    }

    /**
     * Check if an item is a base (has items loaded_with or connected_to it)
     */
    public function isStackBase($index): bool
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return false;
        }
        
        $itemId = $item['id'];
        
        // Check if any other item points to this item
        foreach ($this->items as $otherIndex => $otherItem) {
            if ($otherIndex === $index) {
                continue;
            }
            
            if (isset($otherItem['related_item_id']) && 
                $otherItem['related_item_id'] == $itemId &&
                in_array($otherItem['relationship_type'] ?? 'separate', ['loaded_with', 'connected_to'])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get total unit count for an item (base + all related items recursively)
     * Counts all items in the entire stack/combination, including items loaded onto connected items
     */
    public function getStackUnitCount($index): int
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return $item['quantity'] ?? 1;
        }
        
        if (!$this->isStackBase($index)) {
            return $item['quantity'] ?? 1;
        }
        
        // Recursively collect all item IDs in the stack
        $stackItemIds = $this->getAllStackItemIds($item['id']);
        
        // Sum quantities of all items in the stack
        $totalQuantity = 0;
        foreach ($stackItemIds as $stackItemId) {
            foreach ($this->items as $otherItem) {
                if (($otherItem['id'] ?? null) == $stackItemId) {
                    $totalQuantity += $otherItem['quantity'] ?? 1;
                    break;
                }
            }
        }
        
        return $totalQuantity;
    }

    /**
     * Recursively get all item IDs in a stack/combination
     * Traverses the relationship chain to find all connected and loaded items
     */
    private function getAllStackItemIds($baseItemId, &$visited = []): array
    {
        if (!$baseItemId || in_array($baseItemId, $visited)) {
            return [];
        }
        
        $itemIds = [$baseItemId];
        $visited[] = $baseItemId;
        
        // Find all items pointing to this item
        foreach ($this->items as $otherItem) {
            if (isset($otherItem['related_item_id']) && 
                $otherItem['related_item_id'] == $baseItemId &&
                in_array($otherItem['relationship_type'] ?? 'separate', ['loaded_with', 'connected_to'])) {
                
                $otherItemId = $otherItem['id'] ?? null;
                if ($otherItemId && !in_array($otherItemId, $visited)) {
                    // Recursively get items pointing to this item
                    $nestedIds = $this->getAllStackItemIds($otherItemId, $visited);
                    $itemIds = array_merge($itemIds, $nestedIds);
                }
            }
        }
        
        return $itemIds;
    }

    /**
     * Get all unique stack groups and assign numbers
     * Returns array: ['base_item_id' => stack_number, ...]
     */
    public function getStackNumbers(): array
    {
        $stackNumbers = [];
        $stackNumber = 1;
        $processedBases = [];

        foreach ($this->items as $index => $item) {
            if (!isset($item['id']) || str_starts_with($item['id'], 'temp_')) {
                continue;
            }

            $baseId = $this->getStackBaseId($index);
            if ($baseId && !in_array($baseId, $processedBases)) {
                $stackNumbers[$baseId] = $stackNumber;
                $processedBases[] = $baseId;
                $stackNumber++;
            }
        }

        return $stackNumbers;
    }

    /**
     * Get the stack base ID for an item
     */
    private function getStackBaseId($index, $visited = []): ?string
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return null;
        }

        // Prevent infinite recursion
        if (in_array($item['id'], $visited)) {
            return null;
        }
        $visited[] = $item['id'];

        // If this item is a base (others point to it), return its ID
        if ($this->isStackBase($index)) {
            return $item['id'];
        }

        // If this item is linked to another, find the base
        if (isset($item['related_item_id']) && !empty($item['related_item_id'])) {
            $relatedId = $item['related_item_id'];
            
            // Find the related item
            foreach ($this->items as $otherIndex => $otherItem) {
                if (isset($otherItem['id']) && $otherItem['id'] == $relatedId) {
                    // Recursively find the base
                    return $this->getStackBaseId($otherIndex, $visited);
                }
            }
        }

        // If separate item, return null (not in a stack)
        return null;
    }

    /**
     * Get the stack number for a specific item
     * Returns null if item is not in a stack
     */
    public function getStackNumber($index): ?int
    {
        $baseId = $this->getStackBaseId($index);
        if (!$baseId) {
            return null;
        }

        $stackNumbers = $this->getStackNumbers();
        return $stackNumbers[$baseId] ?? null;
    }

    /**
     * Check if this item is the base for showing overall dimensions
     * Only the ultimate base (not connected to anything else) should show overall dimensions
     */
    public function isStackBaseForDimensions($index): bool
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return false;
        }

        // Item must be a base (others point to it)
        if (!$this->isStackBase($index)) {
            return false;
        }

        // Item must NOT be connected to anything else (it's the ultimate base)
        // If item has a related_item_id and is connected_to something, it's not the ultimate base
        if (isset($item['related_item_id']) && 
            !empty($item['related_item_id']) &&
            ($item['relationship_type'] ?? 'separate') === 'connected_to') {
            return false;
        }

        return true;
    }

    /**
     * Get the ultimate base index for an item (follows chain of connected_to relationships)
     * Returns the index of the true base item
     */
    public function getUltimateBaseIndex($index): ?int
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return null;
        }

        $currentIndex = $index;
        $visited = [];

        while (true) {
            if (in_array($currentIndex, $visited)) {
                break; // Prevent infinite loops
            }
            $visited[] = $currentIndex;

            $currentItem = $this->items[$currentIndex] ?? null;
            if (!$currentItem || !isset($currentItem['id'])) {
                break;
            }

            // If this item is connected to something, follow the chain
            if (isset($currentItem['related_item_id']) && 
                !empty($currentItem['related_item_id']) &&
                ($currentItem['relationship_type'] ?? 'separate') === 'connected_to') {
                
                // Find the parent item
                $parentIndex = null;
                foreach ($this->items as $idx => $parentItem) {
                    if ($idx === $currentIndex || in_array($idx, $visited)) {
                        continue;
                    }
                    if (($parentItem['id'] ?? null) == ($currentItem['related_item_id'] ?? null)) {
                        $parentIndex = $idx;
                        break;
                    }
                }

                if ($parentIndex === null) {
                    break; // Can't find parent, stop here
                }

                $currentIndex = $parentIndex;
            } else {
                // This is the ultimate base
                break;
            }
        }

        return $currentIndex;
    }

    /**
     * Check if an item is a true base (not connected/loaded to anything else)
     */
    public function isTrueBase($index): bool
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return false;
        }

        $relationshipType = $item['relationship_type'] ?? 'separate';
        $hasRelatedItem = !empty($item['related_item_id'] ?? null);

        // True base: relationship_type is 'separate' or null, and has no related_item_id
        return ($relationshipType === 'separate' || $relationshipType === null) && !$hasRelatedItem;
    }

    /**
     * Check if an item is connected to another (relationship_type = 'connected_to')
     */
    public function isConnectedItem($index): bool
    {
        $item = $this->items[$index] ?? null;
        if (!$item) {
            return false;
        }

        return ($item['relationship_type'] ?? 'separate') === 'connected_to';
    }

    /**
     * Check if an item is loaded with another (relationship_type = 'loaded_with')
     */
    public function isLoadedItem($index): bool
    {
        $item = $this->items[$index] ?? null;
        if (!$item) {
            return false;
        }

        return ($item['relationship_type'] ?? 'separate') === 'loaded_with';
    }

    /**
     * Get the relationship label for a base item or connected item
     * Returns: "combination", "loaded combination", or "loaded unit"
     * 
     * - "combination": 2 or more commodity types connected to each other
     * - "loaded combination": 2 or more commodity types connected to each other and loaded with one or more commodity types
     * - "loaded unit": a separate commodity type loaded with one or more commodity types
     */
    public function getRelationshipLabel($index): ?string
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return null;
        }
        
        // If item is loaded, return null (handled by getLinkedItemLabel)
        if ($this->isLoadedItem($index)) {
            return null;
        }
        
        // If item is connected, get the label from its ultimate base
        if ($this->isConnectedItem($index)) {
            $ultimateBaseIndex = $this->getUltimateBaseIndex($index);
            if ($ultimateBaseIndex !== null && $ultimateBaseIndex !== $index) {
                return $this->getRelationshipLabel($ultimateBaseIndex);
            }
        }
        
        // Only true bases or items that are bases (have items pointing to them) get labels
        if (!$this->isStackBase($index) && !$this->isTrueBase($index)) {
            return null;
        }
        
        // Count relationship types for the true base
        $hasConnected = false;
        $hasLoaded = false;
        $connectedItemsHaveLoaded = false; // Check if any connected items have loaded items
        
        foreach ($this->items as $otherIndex => $otherItem) {
            if ($otherIndex === $index) {
                continue;
            }
            
            if (($otherItem['related_item_id'] ?? null) == $item['id']) {
                $relType = $otherItem['relationship_type'] ?? 'separate';
                if ($relType === 'connected_to') {
                    $hasConnected = true;
                    
                    // Check if this connected item has any loaded items on it
                    $connectedItemId = $otherItem['id'] ?? null;
                    if ($connectedItemId) {
                        foreach ($this->items as $loadedIndex => $loadedItem) {
                            if ($loadedIndex === $otherIndex || $loadedIndex === $index) {
                                continue;
                            }
                            if (($loadedItem['related_item_id'] ?? null) == $connectedItemId &&
                                ($loadedItem['relationship_type'] ?? 'separate') === 'loaded_with') {
                                $connectedItemsHaveLoaded = true;
                                break;
                            }
                        }
                    }
                } elseif ($relType === 'loaded_with') {
                    $hasLoaded = true;
                }
            }
        }
        
        // Determine label
        // If base has connected items AND any of those connected items have loaded items → "loaded combination"
        if ($hasConnected && $connectedItemsHaveLoaded) {
            return 'loaded combination';
        }
        // If base has both connected and loaded items directly → "loaded combination"
        if ($hasConnected && $hasLoaded) {
            return 'loaded combination';
        }
        // If base has only connected items (and none have loaded items) → "combination"
        if ($hasConnected) {
            return 'combination';
        }
        // If base has only loaded items → "loaded unit"
        if ($hasLoaded) {
            return 'loaded unit';
        }
        
        return null;
    }

    /**
     * Get the badge label for an item
     * Returns: "base unit", "base connected unit", "loaded unit", or null
     */
    public function getBadgeLabel($index): ?string
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return null;
        }
        
        // True base (not connected/loaded to anything): "base unit"
        if ($this->isTrueBase($index)) {
            return 'base unit';
        }
        
        // Connected item: "base connected unit"
        if ($this->isConnectedItem($index)) {
            return 'base connected unit';
        }
        
        // Loaded item: "loaded unit"
        if ($this->isLoadedItem($index)) {
            return 'loaded unit';
        }
        
        // Standalone item (no relationships): no badge
        return null;
    }

    /**
     * Get the relationship label for a loaded item (loaded_with)
     * Returns: "Part of loaded combination item #X" or "Part of loaded unit item #X"
     */
    public function getLinkedItemLabel($index): ?string
    {
        $item = $this->items[$index] ?? null;
        if (!$item || !isset($item['id'])) {
            return null;
        }
        
        // Only handle loaded_with items (connected items are now handled by getRelationshipLabel)
        if (!$this->isLoadedItem($index)) {
            return null;
        }
        
        // Find the direct parent (what this item is directly loaded onto)
        $baseIndex = null;
        foreach ($this->items as $idx => $otherItem) {
            if (($otherItem['id'] ?? null) == ($item['related_item_id'] ?? null)) {
                $baseIndex = $idx;
                break;
            }
        }
        
        // If we can't find the parent, return null
        if ($baseIndex === null) {
            return null;
        }
        
        // Calculate the item number (1-based for display)
        $itemNumber = $baseIndex + 1;
        
        // Find the ultimate base for determining combination type
        $ultimateBaseIndex = $this->getUltimateBaseIndex($index);
        if ($ultimateBaseIndex === null) {
            // Fallback: if we can't find ultimate base, use direct parent
            return "Part of loaded unit item #{$itemNumber}";
        }
        
        // Get the ultimate base's relationship label
        $ultimateBaseLabel = $this->getRelationshipLabel($ultimateBaseIndex);
        
        // Use the ultimate base label to determine what this loaded item is part of
        // Always use the direct parent's item number to show where it's loaded onto
        if ($ultimateBaseLabel === 'loaded combination') {
            return "Part of loaded combination item #{$itemNumber}";
        } elseif ($ultimateBaseLabel === 'loaded unit') {
            return "Part of loaded unit item #{$itemNumber}";
        }
        
        // Fallback: check if the direct parent is connected to something
        $baseItem = $this->items[$baseIndex] ?? null;
        if ($baseItem) {
            // Check if the parent is connected to something
            $parentIsConnected = false;
            foreach ($this->items as $otherIndex => $otherItem) {
                if ($otherIndex === $baseIndex || $otherIndex === $index) {
                    continue;
                }
                if (($otherItem['related_item_id'] ?? null) == ($baseItem['id'] ?? null) &&
                    ($otherItem['relationship_type'] ?? 'separate') === 'connected_to') {
                    $parentIsConnected = true;
                    break;
                }
            }
            
            // If parent is connected to something, this is part of loaded combination
            if ($parentIsConnected) {
                return "Part of loaded combination item #{$itemNumber}";
            }
        }
        
        // Otherwise, it's part of a loaded unit
        return "Part of loaded unit item #{$itemNumber}";
    }

    /**
     * Get all relationship group numbers
     * Groups all true bases by their relationship label type and assigns sequential numbers
     * Always assigns numbers (even for single combinations)
     * Returns array: ['base_item_id' => ['type' => 'combination', 'number' => 1], ...]
     */
    public function getRelationshipGroupNumbers(): array
    {
        $groups = [
            'combination' => [],
            'loaded combination' => [],
            'loaded unit' => [],
        ];
        
        // Group all true bases by their relationship type
        foreach ($this->items as $index => $item) {
            // Only process true bases (not connected items)
            if (!$this->isTrueBase($index)) {
                continue;
            }
            
            $label = $this->getRelationshipLabel($index);
            if ($label && isset($groups[$label])) {
                $baseId = $item['id'] ?? null;
                if ($baseId && !str_starts_with($baseId, 'temp_')) {
                    $groups[$label][] = $baseId;
                }
            }
        }
        
        // Always assign numbers (even for single combinations)
        $result = [];
        foreach ($groups as $type => $baseIds) {
            foreach ($baseIds as $number => $baseId) {
                $result[$baseId] = ['type' => $type, 'number' => $number + 1];
            }
        }
        
        return $result;
    }

    /**
     * Get the relationship number for an item
     * Returns the number for that combination, using the ultimate base's combination type and number
     */
    public function getRelationshipNumber($index): ?int
    {
        // Find the ultimate base for this item
        $ultimateBaseIndex = $this->getUltimateBaseIndex($index);
        if ($ultimateBaseIndex === null) {
            return null;
        }
        
        $ultimateBaseItem = $this->items[$ultimateBaseIndex] ?? null;
        if (!$ultimateBaseItem || !isset($ultimateBaseItem['id'])) {
            return null;
        }
        
        $baseId = $ultimateBaseItem['id'];
        if (str_starts_with($baseId, 'temp_')) {
            return null; // Don't number temporary items
        }
        
        $groupNumbers = $this->getRelationshipGroupNumbers();
        return $groupNumbers[$baseId]['number'] ?? null;
    }

    /**
     * Calculate overall CBM from overall dimensions
     */
    public function calculateStackCbm($index)
    {
        $item = &$this->items[$index];
        
        $length = floatval($item['stack_length_cm'] ?? 0);
        $width = floatval($item['stack_width_cm'] ?? 0);
        $height = floatval($item['stack_height_cm'] ?? 0);
        
        if ($length > 0 && $width > 0 && $height > 0) {
            if ($this->unitSystem === 'us') {
                $cuft = ($length / 12) * ($width / 12) * ($height / 12);
                $item['stack_cbm'] = round($cuft, 2);
            } else {
                $cbm = ($length / 100) * ($width / 100) * ($height / 100);
                $item['stack_cbm'] = round($cbm, 4);
            }
        } else {
            $item['stack_cbm'] = '';
        }
        
        // Also calculate LM when dimensions change
        $this->calculateStackLm($index);
    }

    /**
     * Calculate overall LM from overall dimensions
     */
    public function calculateStackLm($index)
    {
        $item = &$this->items[$index];
        
        $length = floatval($item['stack_length_cm'] ?? 0);
        $width = floatval($item['stack_width_cm'] ?? 0);

        if ($length > 0 && $width > 0) {
            if ($this->unitSystem === 'us') {
                $lengthM = $length / 12 / 0.3048;
                $widthInches = max($width, 250 / 2.54);
                $widthM = $widthInches / 12 / 0.3048;
                $lm = ($lengthM * $widthM) / 2.5;
                $item['stack_lm'] = round($lm, 4);
            } else {
                $lengthM = $length / 100;
                $widthCm = max($width, 250);
                $widthM = $widthCm / 100;
                $lm = ($lengthM * $widthM) / 2.5;
                $item['stack_lm'] = round($lm, 4);
            }
        } else {
            $item['stack_lm'] = '';
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
        // Handle relationship_type changes
        if (preg_match('/^items\.(\d+)\.relationship_type$/', $propertyName, $matches)) {
            $index = (int) $matches[1];
            $currentItem = $this->items[$index];
            $newRelationshipType = $currentItem['relationship_type'] ?? 'separate';
            
            // If relationship_type is set to 'separate', clear related_item_id and update related item
            if ($newRelationshipType === 'separate') {
                $oldRelatedItemId = $currentItem['related_item_id'] ?? null;
                $this->items[$index]['related_item_id'] = null;
                
                // Clear the reverse relationship on the related item
                if ($oldRelatedItemId) {
                    $this->clearReverseRelationship($oldRelatedItemId, $currentItem['id'] ?? null);
                }
            }
        }

        // Handle related_item_id changes - validate and sync bidirectional relationship
        if (preg_match('/^items\.(\d+)\.related_item_id$/', $propertyName, $matches)) {
            $index = (int) $matches[1];
            $currentItem = $this->items[$index];
            $relatedItemId = $currentItem['related_item_id'] ?? null;
            $currentItemId = $currentItem['id'] ?? null;
            $relationshipType = $currentItem['relationship_type'] ?? 'separate';
            
            // Prevent item from relating to itself
            if ($relatedItemId && $currentItemId && $relatedItemId == $currentItemId) {
                $this->items[$index]['related_item_id'] = null;
                session()->flash('error', 'An item cannot be related to itself.');
                return;
            }
            
            // If relationship is being set (not cleared), sync bidirectional relationship
            if ($relatedItemId && in_array($relationshipType, ['connected_to', 'loaded_with'])) {
                // Clear old reverse relationship if item was previously related to something else
                $oldRelatedItemId = $this->getOriginalRelatedItemId($index);
                if ($oldRelatedItemId && $oldRelatedItemId != $relatedItemId) {
                    $this->clearReverseRelationship($oldRelatedItemId, $currentItemId);
                }
                
                // Set reverse relationship on the related item
                $this->setReverseRelationship($relatedItemId, $currentItemId, $relationshipType);
            } elseif (!$relatedItemId) {
                // Relationship is being cleared, clear reverse relationship
                $oldRelatedItemId = $this->getOriginalRelatedItemId($index);
                if ($oldRelatedItemId) {
                    $this->clearReverseRelationship($oldRelatedItemId, $currentItemId);
                }
            }
        }

        // Detect when length_cm or width_cm changes and recalculate LM in real-time
        if (preg_match('/^items\.(\d+)\.(length_cm|width_cm)$/', $propertyName, $matches)) {
            $index = (int) $matches[1];
            $this->calculateLm($index);
            // Also recalculate CBM if height is also set
            if (!empty($this->items[$index]['height_cm'] ?? '')) {
                $this->calculateCbm($index);
            }
        }
        
        // Detect when quantity changes - this will trigger article price recalculation via model boot()
        if (preg_match('/^items\.(\d+)\.quantity$/', $propertyName, $matches)) {
            $index = (int) $matches[1];
            $newQuantity = $this->items[$index]['quantity'] ?? 1;
            
            \Log::info('CommodityItemsRepeater: quantity changed', [
                'index' => $index,
                'old_quantity' => $this->items[$index]['quantity'] ?? 'unknown',
                'new_quantity' => $newQuantity,
                'item_id' => $this->items[$index]['id'] ?? 'none',
                'quotation_id' => $this->quotationId,
                'has_database_id' => isset($this->items[$index]['id']) && is_numeric($this->items[$index]['id']),
            ]);
            
            // Recalculate LM to ensure quantity is included in the calculation
            if (!empty($this->items[$index]['length_cm'] ?? '') && !empty($this->items[$index]['width_cm'] ?? '')) {
                $this->calculateLm($index);
            }
        }
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
        
        // Auto-save item to database when any field is updated
        if ($itemIndex !== null && isset($this->items[$itemIndex]) && $this->quotationId) {
            $item = $this->items[$itemIndex];
            
            // Check if this is a commodity_type change and item doesn't have a database ID yet
            if (strpos($propertyName, '.commodity_type') !== false && !empty($item['commodity_type'])) {
                \Log::info('CommodityItemsRepeater: commodity_type selected', [
                    'property' => $propertyName,
                    'index' => $itemIndex,
                    'commodity_type' => $item['commodity_type'],
                    'item_id' => $item['id'] ?? 'none',
                    'quotation_id' => $this->quotationId,
                    'is_temp_id' => isset($item['id']) && strpos($item['id'], 'temp_') === 0
                ]);
                
                // If item has a temporary ID and commodity_type is now set, create database record
                if (isset($item['id']) && strpos($item['id'], 'temp_') === 0) {
                    $this->createItemInDatabase($itemIndex, $item);
                    return; // Don't continue to saveItemToDatabase - we just created it
                }
            }
            
            // Only save if item has a database ID (not a temporary ID)
            if (isset($item['id']) && is_numeric($item['id'])) {
                $this->saveItemToDatabase($itemIndex, $item);
            }
        }
    }
    
    /**
     * Create item in database when commodity_type is first selected
     */
    protected function createItemInDatabase($index, $item)
    {
        \Log::info('CommodityItemsRepeater::createItemInDatabase() called', [
            'quotation_id' => $this->quotationId,
            'commodity_type' => $item['commodity_type'] ?? 'empty',
            'index' => $index,
            'has_quotation_id' => !empty($this->quotationId),
            'has_commodity_type' => !empty($item['commodity_type'])
        ]);
        
        if (!$this->quotationId || empty($item['commodity_type'])) {
            \Log::warning('CommodityItemsRepeater::createItemInDatabase() skipped', [
                'quotation_id' => $this->quotationId,
                'commodity_type' => $item['commodity_type'] ?? 'empty',
                'reason' => !$this->quotationId ? 'no_quotation_id' : 'no_commodity_type'
            ]);
            return;
        }
        
        try {
            // Prepare data for database creation
            $data = [
                'quotation_request_id' => $this->quotationId,
                'line_number' => $index + 1,
                'relationship_type' => $item['relationship_type'] ?? 'separate',
                'related_item_id' => $this->resolveRelatedItemId($item['related_item_id'] ?? null),
                'commodity_type' => $item['commodity_type'],
                'category' => $item['category'] ?? null,
                'make' => $item['make'] ?? null,
                'type_model' => $item['type_model'] ?? null,
                'fuel_type' => $item['fuel_type'] ?? null,
                'condition' => $item['condition'] ?? null,
                'year' => !empty($item['year']) ? (int) $item['year'] : null,
                'wheelbase_cm' => !empty($item['wheelbase_cm']) ? (float) $item['wheelbase_cm'] : null,
                'quantity' => !empty($item['quantity']) ? (int) $item['quantity'] : 1,
                'length_cm' => !empty($item['length_cm']) ? (float) $item['length_cm'] : null,
                'width_cm' => !empty($item['width_cm']) ? (float) $item['width_cm'] : null,
                'height_cm' => !empty($item['height_cm']) ? (float) $item['height_cm'] : null,
                'cbm' => !empty($item['cbm']) ? (float) $item['cbm'] : null,
                'lm' => !empty($item['lm']) ? (float) $item['lm'] : null,
                'weight_kg' => !empty($item['weight_kg']) ? (float) $item['weight_kg'] : null,
                'bruto_weight_kg' => !empty($item['bruto_weight_kg']) ? (float) $item['bruto_weight_kg'] : null,
                'netto_weight_kg' => !empty($item['netto_weight_kg']) ? (float) $item['netto_weight_kg'] : null,
                'has_parts' => $item['has_parts'] ?? false,
                'parts_description' => $item['parts_description'] ?? null,
                'has_trailer' => $item['has_trailer'] ?? false,
                'has_wooden_cradle' => $item['has_wooden_cradle'] ?? false,
                'has_iron_cradle' => $item['has_iron_cradle'] ?? false,
                'is_forkliftable' => $item['is_forkliftable'] ?? false,
                'is_hazardous' => $item['is_hazardous'] ?? false,
                'is_unpacked' => $item['is_unpacked'] ?? false,
                'is_ispm15' => $item['is_ispm15'] ?? false,
                'extra_info' => $item['extra_info'] ?? null,
                'attachments' => $item['attachments'] ?? [],
                'input_unit_system' => $item['input_unit_system'] ?? $this->unitSystem,
                'stack_length_cm' => !empty($item['stack_length_cm']) ? (float) $item['stack_length_cm'] : null,
                'stack_width_cm' => !empty($item['stack_width_cm']) ? (float) $item['stack_width_cm'] : null,
                'stack_height_cm' => !empty($item['stack_height_cm']) ? (float) $item['stack_height_cm'] : null,
                'stack_weight_kg' => !empty($item['stack_weight_kg']) ? (float) $item['stack_weight_kg'] : null,
                'stack_cbm' => !empty($item['stack_cbm']) ? (float) $item['stack_cbm'] : null,
                'stack_lm' => !empty($item['stack_lm']) ? (float) $item['stack_lm'] : null,
                'stack_unit_count' => !empty($item['stack_unit_count']) ? (int) $item['stack_unit_count'] : null,
            ];
            
            // Create item in database
            $dbItem = \App\Models\QuotationCommodityItem::create($data);
            
            // Update the item's ID from temporary to database ID
            $this->items[$index]['id'] = $dbItem->id;
            
            // Touch parent quotation to update updated_at timestamp
            // This ensures cache keys change when commodity items are created
            \App\Models\QuotationRequest::where('id', $this->quotationId)->touch();
            
            \Log::info('CommodityItemsRepeater::createItemInDatabase() created item', [
                'item_id' => $dbItem->id,
                'quotation_id' => $this->quotationId,
                'commodity_type' => $item['commodity_type'],
                'temp_id' => $item['id']
            ]);
            
            // Dispatch event to parent component when commodity_type is saved
            $this->dispatch('commodity-item-saved', [
                'quotation_id' => $this->quotationId
            ]);
        } catch (\Exception $e) {
            \Log::error('CommodityItemsRepeater::createItemInDatabase() failed', [
                'quotation_id' => $this->quotationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Save item to database (auto-save on field update)
     */
    protected function saveItemToDatabase($index, $item)
    {
        if (!$this->quotationId || !isset($item['id']) || !is_numeric($item['id'])) {
            return;
        }
        
        try {
            // Prepare data for database save
            $data = [
                'line_number' => $index + 1,
                'relationship_type' => $item['relationship_type'] ?? 'separate',
                'related_item_id' => $this->resolveRelatedItemId($item['related_item_id'] ?? null),
                'commodity_type' => $item['commodity_type'] ?? null,
                'category' => $item['category'] ?? null,
                'make' => $item['make'] ?? null,
                'type_model' => $item['type_model'] ?? null,
                'fuel_type' => $item['fuel_type'] ?? null,
                'condition' => $item['condition'] ?? null,
                'year' => !empty($item['year']) ? (int) $item['year'] : null,
                'wheelbase_cm' => !empty($item['wheelbase_cm']) ? (float) $item['wheelbase_cm'] : null,
                'quantity' => !empty($item['quantity']) ? (int) $item['quantity'] : 1,
                'length_cm' => !empty($item['length_cm']) ? (float) $item['length_cm'] : null,
                'width_cm' => !empty($item['width_cm']) ? (float) $item['width_cm'] : null,
                'height_cm' => !empty($item['height_cm']) ? (float) $item['height_cm'] : null,
                'cbm' => !empty($item['cbm']) ? (float) $item['cbm'] : null,
                'lm' => !empty($item['lm']) ? (float) $item['lm'] : null,
                'weight_kg' => !empty($item['weight_kg']) ? (float) $item['weight_kg'] : null,
                'bruto_weight_kg' => !empty($item['bruto_weight_kg']) ? (float) $item['bruto_weight_kg'] : null,
                'netto_weight_kg' => !empty($item['netto_weight_kg']) ? (float) $item['netto_weight_kg'] : null,
                'has_parts' => $item['has_parts'] ?? false,
                'parts_description' => $item['parts_description'] ?? null,
                'has_trailer' => $item['has_trailer'] ?? false,
                'has_wooden_cradle' => $item['has_wooden_cradle'] ?? false,
                'has_iron_cradle' => $item['has_iron_cradle'] ?? false,
                'is_forkliftable' => $item['is_forkliftable'] ?? false,
                'is_hazardous' => $item['is_hazardous'] ?? false,
                'is_unpacked' => $item['is_unpacked'] ?? false,
                'is_ispm15' => $item['is_ispm15'] ?? false,
                'extra_info' => $item['extra_info'] ?? null,
                'attachments' => $item['attachments'] ?? [],
                'input_unit_system' => $item['input_unit_system'] ?? $this->unitSystem,
                'stack_length_cm' => !empty($item['stack_length_cm']) ? (float) $item['stack_length_cm'] : null,
                'stack_width_cm' => !empty($item['stack_width_cm']) ? (float) $item['stack_width_cm'] : null,
                'stack_height_cm' => !empty($item['stack_height_cm']) ? (float) $item['stack_height_cm'] : null,
                'stack_weight_kg' => !empty($item['stack_weight_kg']) ? (float) $item['stack_weight_kg'] : null,
                'stack_cbm' => !empty($item['stack_cbm']) ? (float) $item['stack_cbm'] : null,
                'stack_lm' => !empty($item['stack_lm']) ? (float) $item['stack_lm'] : null,
                'stack_unit_count' => !empty($item['stack_unit_count']) ? (int) $item['stack_unit_count'] : null,
            ];
            
            // Update item in database using model instance to trigger events
            $dbItem = \App\Models\QuotationCommodityItem::where('id', $item['id'])
                ->where('quotation_request_id', $this->quotationId)
                ->first();
            
            if ($dbItem) {
                $dbItem->fill($data);
                $dbItem->save(); // This will trigger saved event which recalculates articles
            }
            
            // Touch parent quotation to update updated_at timestamp
            // This ensures cache keys change when commodity items are modified
            \App\Models\QuotationRequest::where('id', $this->quotationId)->touch();
            
            \Log::info('CommodityItemsRepeater::saveItemToDatabase() saved item', [
                'item_id' => $item['id'],
                'quotation_id' => $this->quotationId,
                'commodity_type' => $item['commodity_type'] ?? null,
                'quantity' => $data['quantity'],
                'length_cm' => $data['length_cm'],
                'width_cm' => $data['width_cm'],
                'lm' => $data['lm'],
            ]);
            
            // Dispatch event to parent component when commodity_type is saved
            // This triggers refresh of SmartArticleSelector
            if (!empty($item['commodity_type'])) {
                $this->dispatch('commodity-item-saved', [
                    'quotation_id' => $this->quotationId
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('CommodityItemsRepeater::saveItemToDatabase() failed', [
                'item_id' => $item['id'] ?? null,
                'quotation_id' => $this->quotationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Set reverse relationship on related item
     * When Item A is set to "Connected to Item B", automatically set Item B to "Connected to Item A"
     */
    protected function setReverseRelationship($relatedItemId, $currentItemId, $relationshipType)
    {
        if (!$relatedItemId || !$currentItemId) {
            return;
        }
        
        // Find the related item in the items array
        $relatedItemIndex = null;
        foreach ($this->items as $idx => $item) {
            $itemId = $item['id'] ?? null;
            // Match by database ID or temporary ID
            if ($itemId && (
                (is_numeric($itemId) && is_numeric($relatedItemId) && $itemId == $relatedItemId) ||
                ($itemId === $relatedItemId)
            )) {
                $relatedItemIndex = $idx;
                break;
            }
        }
        
        if ($relatedItemIndex !== null) {
            // Update the related item to have the reverse relationship
            $this->items[$relatedItemIndex]['relationship_type'] = $relationshipType;
            $this->items[$relatedItemIndex]['related_item_id'] = $currentItemId;
            
            // Save the related item to database if it has a database ID
            if (isset($this->items[$relatedItemIndex]['id']) && is_numeric($this->items[$relatedItemIndex]['id']) && $this->quotationId) {
                $this->saveItemToDatabase($relatedItemIndex, $this->items[$relatedItemIndex]);
            }
            
            \Log::info('CommodityItemsRepeater: Set reverse relationship', [
                'current_item_id' => $currentItemId,
                'related_item_id' => $relatedItemId,
                'relationship_type' => $relationshipType,
                'related_item_index' => $relatedItemIndex,
            ]);
        }
    }
    
    /**
     * Clear reverse relationship on related item
     * When Item A's relationship is cleared, clear Item B's relationship too
     */
    protected function clearReverseRelationship($relatedItemId, $currentItemId)
    {
        if (!$relatedItemId || !$currentItemId) {
            return;
        }
        
        // Find the related item in the items array
        $relatedItemIndex = null;
        foreach ($this->items as $idx => $item) {
            $itemId = $item['id'] ?? null;
            $itemRelatedId = $item['related_item_id'] ?? null;
            
            // Match by database ID or temporary ID, and check if it's related to current item
            if ($itemId && (
                (is_numeric($itemId) && is_numeric($relatedItemId) && $itemId == $relatedItemId) ||
                ($itemId === $relatedItemId)
            )) {
                // Check if this item is related to the current item
                if ($itemRelatedId && (
                    (is_numeric($itemRelatedId) && is_numeric($currentItemId) && $itemRelatedId == $currentItemId) ||
                    ($itemRelatedId === $currentItemId)
                )) {
                    $relatedItemIndex = $idx;
                    break;
                }
            }
        }
        
        if ($relatedItemIndex !== null) {
            // Clear the reverse relationship
            $this->items[$relatedItemIndex]['relationship_type'] = 'separate';
            $this->items[$relatedItemIndex]['related_item_id'] = null;
            
            // Save the related item to database if it has a database ID
            if (isset($this->items[$relatedItemIndex]['id']) && is_numeric($this->items[$relatedItemIndex]['id']) && $this->quotationId) {
                $this->saveItemToDatabase($relatedItemIndex, $this->items[$relatedItemIndex]);
            }
            
            \Log::info('CommodityItemsRepeater: Cleared reverse relationship', [
                'current_item_id' => $currentItemId,
                'related_item_id' => $relatedItemId,
                'related_item_index' => $relatedItemIndex,
            ]);
        }
    }
    
    /**
     * Get the original related_item_id before the update
     * This is used to detect when a relationship changes from one item to another
     */
    protected function getOriginalRelatedItemId($index)
    {
        // We need to track the previous value
        // For now, we'll check the database if the item has a database ID
        if (isset($this->items[$index]['id']) && is_numeric($this->items[$index]['id']) && $this->quotationId) {
            $dbItem = \App\Models\QuotationCommodityItem::where('id', $this->items[$index]['id'])
                ->where('quotation_request_id', $this->quotationId)
                ->first();
            
            if ($dbItem) {
                return $dbItem->related_item_id;
            }
        }
        
        return null;
    }

    /**
     * Resolve related item ID from temporary ID to database ID
     * If related_item_id is a temporary ID (starts with 'temp_'), find the corresponding database ID
     */
    protected function resolveRelatedItemId($relatedItemId)
    {
        if (empty($relatedItemId)) {
            return null;
        }

        // If it's already a numeric ID, return it
        if (is_numeric($relatedItemId)) {
            return (int) $relatedItemId;
        }

        // If it's a temporary ID, find the corresponding item in $this->items
        if (is_string($relatedItemId) && strpos($relatedItemId, 'temp_') === 0) {
            foreach ($this->items as $item) {
                if (isset($item['id']) && $item['id'] === $relatedItemId) {
                    // If this item has a database ID, return it
                    if (isset($item['id']) && is_numeric($item['id'])) {
                        return (int) $item['id'];
                    }
                    // Otherwise, the related item hasn't been saved yet, return null
                    return null;
                }
            }
        }

        return null;
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
