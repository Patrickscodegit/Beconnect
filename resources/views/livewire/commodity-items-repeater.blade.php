<div>
    <!-- Unit System Toggle -->
    <div class="mb-6 bg-blue-50 p-4 rounded-lg border border-blue-200">
        <div class="flex items-center justify-between">
            <label class="text-sm font-semibold text-blue-900">
                <i class="fas fa-ruler mr-2"></i>Measurement Units
            </label>
            <div class="flex space-x-4">
                <label class="flex items-center cursor-pointer">
                    <input type="radio" wire:model.live="unitSystem" value="metric" class="w-5 h-5 text-blue-600">
                    <span class="ml-2 text-sm font-medium text-gray-700">Metric (cm, kg, m³)</span>
                </label>
                <label class="flex items-center cursor-pointer">
                    <input type="radio" wire:model.live="unitSystem" value="us" class="w-5 h-5 text-blue-600">
                    <span class="ml-2 text-sm font-medium text-gray-700">US (inch, lbs, ft³)</span>
                </label>
            </div>
        </div>
        <p class="text-xs text-blue-700 mt-2">
            <i class="fas fa-info-circle mr-1"></i>
            Select your preferred units. All values will be converted to metric for processing.
        </p>
    </div>

    <!-- Commodity Items -->
    <div class="border-2 border-dashed border-blue-300 rounded-lg p-6 bg-blue-50">
        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-list-ul text-blue-600 mr-2"></i>
                Commodity Items ({{ count($items) }})
            </h3>
        </div>

        @if(count($items) > 0)
            <div class="space-y-4">
                @foreach($items as $index => $item)
                    <div class="bg-white rounded-lg border border-gray-200 p-6 shadow-sm" 
                         wire:key="item-{{ $item['id'] ?? $index }}">
                        
                        <!-- Item Header -->
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-lg font-semibold text-gray-700">
                                <i class="fas fa-cube text-gray-500 mr-2"></i>
                                Item #{{ $index + 1 }}
                                @if(in_array($item['relationship_type'] ?? 'separate', ['connected_to', 'loaded_with']) && !empty($item['related_item_id'] ?? null))
                                    @php
                                        $relatedIndex = null;
                                        foreach($items as $idx => $relItem) {
                                            if(($relItem['id'] ?? null) == ($item['related_item_id'] ?? null)) {
                                                $relatedIndex = $idx;
                                                break;
                                            }
                                        }
                                    @endphp
                                    @if($relatedIndex !== null)
                                        <span class="text-sm font-normal text-gray-500 ml-2">
                                            ({{ $item['relationship_type'] === 'connected_to' ? 'Connected to' : 'Loaded with' }} Item #{{ $relatedIndex + 1 }})
                                        </span>
                                    @endif
                                @endif
                            </h4>
                            <button 
                                type="button"
                                wire:click="removeItem({{ $index }})"
                                class="text-red-600 hover:text-red-700 px-3 py-1 rounded hover:bg-red-50 transition-colors">
                                <i class="fas fa-trash mr-1"></i>Remove
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <!-- Commodity Type -->
                            <div class="lg:col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Commodity Type <span class="text-red-500">*</span>
                                </label>
                                <select 
                                    wire:model.live="items.{{ $index }}.commodity_type"
                                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                    <option value="">Select Type</option>
                                    @foreach($commodityTypes as $key => $config)
                                        <option value="{{ $key }}">{{ $config['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Relationship Type -->
                            <div class="lg:col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Relationship
                                </label>
                                <select 
                                    wire:model.live="items.{{ $index }}.relationship_type"
                                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                    <option value="separate">Separate unit</option>
                                    <option value="connected_to">Connected to</option>
                                    <option value="loaded_with">Loaded with</option>
                                </select>
                            </div>

                            <!-- Related Item Selector (shown when connected_to or loaded_with) -->
                            @if(in_array($item['relationship_type'] ?? 'separate', ['connected_to', 'loaded_with']))
                            <div class="lg:col-span-3">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ $item['relationship_type'] === 'connected_to' ? 'Connected to' : 'Loaded with' }} Item #
                                </label>
                                <select 
                                    wire:model.live="items.{{ $index }}.related_item_id"
                                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                    <option value="">Select Item</option>
                                    @foreach($items as $relatedIndex => $relatedItem)
                                        @if($relatedIndex !== $index && ($relatedItem['id'] ?? null))
                                            @php
                                                $itemNumber = $relatedIndex + 1;
                                                $itemType = $relatedItem['commodity_type'] ?? 'N/A';
                                                $itemDisplay = "Item #{$itemNumber}";
                                                if ($itemType !== 'N/A' && $itemType !== '') {
                                                    $itemDisplay .= " - " . ucfirst($itemType);
                                                }
                                                if (!empty($relatedItem['make'] ?? '')) {
                                                    $itemDisplay .= " (" . $relatedItem['make'];
                                                    if (!empty($relatedItem['type_model'] ?? '')) {
                                                        $itemDisplay .= " " . $relatedItem['type_model'];
                                                    }
                                                    $itemDisplay .= ")";
                                                }
                                            @endphp
                                            <option value="{{ $relatedItem['id'] }}">
                                                {{ $itemDisplay }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                                @if(empty($item['related_item_id']))
                                    <p class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Select an item that has been saved (has a commodity type selected).
                                    </p>
                                @endif
                            </div>
                            @endif

                            @if($item['commodity_type'])
                                @include('livewire.commodity-forms.' . $item['commodity_type'], ['index' => $index, 'item' => $item])
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            
            <!-- Add Item Button at Bottom -->
            <div class="mt-6 flex justify-center">
                <button 
                    type="button"
                    wire:click="addItem" 
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-plus mr-2"></i>Add Item
                </button>
            </div>
        @else
            <div class="text-center py-12 bg-white rounded-lg border-2 border-dashed border-gray-300">
                <i class="fas fa-inbox text-gray-400 text-5xl mb-4"></i>
                <p class="text-gray-600 text-lg mb-4">No commodity items added yet</p>
                <button 
                    type="button"
                    wire:click="addItem" 
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-plus mr-2"></i>Add Your First Item
                </button>
            </div>
        @endif
    </div>

    <!-- Hidden field to submit items data -->
    <input type="hidden" name="commodity_items" value="{{ json_encode($items) }}">
    <input type="hidden" name="unit_system" value="{{ $unitSystem }}">
</div>
