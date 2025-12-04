{{-- Stack Dimensions (shown when item is stack base) --}}
@if($this->isStackBase($index))
<div class="lg:col-span-3 mt-4 pt-4 border-t-2 border-blue-300">
    <h6 class="font-semibold text-blue-900 mb-3">
        <i class="fas fa-layer-group mr-2"></i>Stack Dimensions (Overall)
    </h6>
    <div class="bg-blue-100 p-3 rounded-lg mb-3">
        <p class="text-sm text-blue-800">
            <i class="fas fa-info-circle mr-1"></i>
            <strong>Units in Stack:</strong> {{ $this->getStackUnitCount($index) }}
        </p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {{-- Stack Length --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Stack Length <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.stack_length_cm"
                wire:change="calculateStackCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Stack Width --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Stack Width <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.stack_width_cm"
                wire:change="calculateStackCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Stack Height --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Stack Height <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.stack_height_cm"
                wire:change="calculateStackCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Stack Weight --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Stack Weight
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'kg' : 'lbs' }})</span>
            </label>
            <input type="number" 
                wire:model.blur="items.{{ $index }}.stack_weight_kg"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Stack CBM (Auto-calculated) --}}
        <div>
            <label class="block text-sm font-medium text-purple-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                Stack {{ $unitSystem === 'metric' ? 'CBM (m³)' : 'Cubic Feet (ft³)' }}
            </label>
            <input type="text" 
                value="{{ $item['stack_cbm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-purple-400 bg-gradient-to-r from-purple-500 to-purple-600 text-white font-bold"
                placeholder="Auto-calculated">
        </div>

        {{-- Stack LM (Auto-calculated) --}}
        <div>
            <label class="block text-sm font-medium text-blue-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                Stack LM (Linear Meter)
            </label>
            <input type="text" 
                value="{{ $item['stack_lm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-blue-400 bg-gradient-to-r from-blue-500 to-blue-600 text-white font-bold"
                placeholder="Auto-calculated">
        </div>
    </div>
</div>
@endif

