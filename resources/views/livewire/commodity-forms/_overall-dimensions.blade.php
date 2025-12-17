{{-- Overall Dimensions (shown when item is base for dimensions) --}}
@if($this->isStackBaseForDimensions($index))
@php
    $relationshipLabel = $this->getRelationshipLabel($index);
    $relationshipNumber = $this->getRelationshipNumber($index);
    $relationshipLabelDisplay = $relationshipLabel;
    if ($relationshipNumber !== null && $relationshipLabel) {
        $relationshipLabelDisplay = $relationshipLabel . ' #' . $relationshipNumber;
    }
@endphp
<div class="lg:col-span-3 mt-4 pt-4 border-t-2 border-blue-300" x-data="{ expanded: false }">
    <div class="flex justify-between items-center mb-3">
        <h6 class="font-semibold text-blue-900">
            <i class="fas fa-layer-group mr-2"></i>Overall Dimensions
            @if($relationshipLabelDisplay)
                (Entire {{ ucfirst($relationshipLabelDisplay) }})
            @else
                (Entire Combination)
            @endif
        </h6>
        <button 
            type="button"
            @click="expanded = !expanded"
            class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1 transition-colors">
            <i class="fas transition-transform duration-200" :class="expanded ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
        </button>
    </div>
    <div x-show="expanded" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 max-h-0"
         x-transition:enter-end="opacity-100 max-h-screen"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 max-h-screen"
         x-transition:leave-end="opacity-0 max-h-0"
         class="overflow-hidden">
        <div class="bg-blue-100 p-3 rounded-lg mb-3">
            <p class="text-sm text-blue-800">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>Total Units:</strong> {{ $this->getStackUnitCount($index) }}
            </p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {{-- Overall Length --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Overall Length <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.stack_length_cm"
                wire:change="calculateStackCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Overall Width --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Overall Width <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.stack_width_cm"
                wire:change="calculateStackCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Overall Height --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Overall Height <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.stack_height_cm"
                wire:change="calculateStackCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Overall Weight --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Overall Weight
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'kg' : 'lbs' }})</span>
            </label>
            <input type="number" 
                wire:model.blur="items.{{ $index }}.stack_weight_kg"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Overall CBM (Auto-calculated) --}}
        <div>
            <label class="block text-sm font-medium text-purple-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                Overall {{ $unitSystem === 'metric' ? 'CBM (m³)' : 'Cubic Feet (ft³)' }}
            </label>
            <input type="text" 
                value="{{ $item['stack_cbm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-purple-400 bg-gradient-to-r from-purple-500 to-purple-600 text-white font-bold"
                placeholder="Auto-calculated">
        </div>

        {{-- Overall LM (Auto-calculated) --}}
        <div>
            <label class="block text-sm font-medium text-blue-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                Overall LM (Linear Meter)
            </label>
            <input type="text" 
                value="{{ $item['stack_lm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-blue-400 bg-gradient-to-r from-blue-500 to-blue-600 text-white font-bold"
                placeholder="Auto-calculated">
        </div>
        </div>
    </div>
</div>
@endif
