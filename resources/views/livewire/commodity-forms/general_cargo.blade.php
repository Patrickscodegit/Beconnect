{{-- General Cargo Form --}}
<div class="lg:col-span-3 bg-green-50 p-4 rounded-lg border border-green-200">
    <h5 class="font-semibold text-green-900 mb-3">
        <i class="fas fa-boxes mr-2"></i>General Cargo Details
    </h5>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @if(!empty($item['category']))
        {{-- Checkboxes --}}
        <div class="lg:col-span-3 bg-green-100 p-3 rounded-lg space-y-2">
            {{-- Forkliftable (hidden for palletized) --}}
            @if(!in_array($item['category'] ?? '', config('quotation.commodity_types.general_cargo.forkliftable_hidden_for', [])))
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" 
                        wire:model="items.{{ $index }}.is_forkliftable"
                        class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                    <span class="ml-3 text-sm font-medium text-gray-700">
                        <i class="fas fa-dolly mr-1"></i>Forkliftable
                    </span>
                </label>
            @endif

            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model="items.{{ $index }}.is_hazardous"
                    class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-exclamation-triangle mr-1 text-red-500"></i>Hazardous
                </span>
            </label>

            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model="items.{{ $index }}.is_unpacked"
                    class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-box-open mr-1"></i>Unpacked
                </span>
            </label>

            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model="items.{{ $index }}.is_ispm15"
                    class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-certificate mr-1"></i>ISPM15 Wood
                </span>
            </label>
        </div>

        {{-- Dimensions --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Length <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.length_cm"
                wire:change="calculateCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Width <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.width_cm"
                wire:change="calculateCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Height <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.height_cm"
                wire:change="calculateCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- CBM/CuFt --}}
        <div>
            <label class="block text-sm font-medium text-purple-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                {{ $unitSystem === 'metric' ? 'CBM (m³)' : 'Cubic Feet (ft³)' }}
            </label>
            <input type="text" 
                value="{{ $item['cbm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-purple-400 bg-gradient-to-r from-purple-500 to-purple-600 text-white font-bold">
        </div>

        {{-- LM (Linear Meter) (Auto-calculated) --}}
        <div>
            <label class="block text-sm font-medium text-blue-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                LM (Linear Meter)
            </label>
            <input type="text" 
                value="{{ $item['lm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-blue-400 bg-gradient-to-r from-blue-500 to-blue-600 text-white font-bold"
                placeholder="Auto-calculated">
            <p class="text-xs text-blue-600 mt-1">Auto-calculated: (L × W) / 2.5</p>
        </div>

        {{-- Bruto Weight --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Bruto Weight <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'kg' : 'lbs' }})</span>
            </label>
            <input type="number" 
                wire:model.blur="items.{{ $index }}.bruto_weight_kg"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Netto Weight --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Netto Weight <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'kg' : 'lbs' }})</span>
            </label>
            <input type="number" 
                wire:model.blur="items.{{ $index }}.netto_weight_kg"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Quantity --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Quantity <span class="text-red-500">*</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.quantity"
                min="1"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Extra Info --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">Extra Info</label>
            <textarea 
                wire:model="items.{{ $index }}.extra_info"
                rows="2"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"></textarea>
        </div>
        @endif
    </div>
</div>

