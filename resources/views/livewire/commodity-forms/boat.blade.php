{{-- Boat Form --}}
<div class="lg:col-span-3 bg-cyan-50 p-4 rounded-lg border border-cyan-200" x-data="{ expanded: false }">
    <div class="flex justify-between items-center mb-3">
        <h5 class="font-semibold text-cyan-900">
            <i class="fas fa-ship mr-2"></i>Boat Details
        </h5>
        <button 
            type="button"
            @click="expanded = !expanded"
            class="text-cyan-600 hover:text-cyan-800 font-medium text-sm flex items-center gap-1 transition-colors">
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
         class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 overflow-hidden">
        
        {{-- Condition --}}
        <div class="lg:col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Condition <span class="text-red-500">*</span>
            </label>
            <select 
                wire:model="items.{{ $index }}.condition"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="">Select Condition</option>
                @foreach(config('quotation.commodity_types.boat.condition_types') as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Make --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Make <span class="text-red-500">*</span>
            </label>
            <input type="text" 
                wire:model.blur="items.{{ $index }}.make"
                class="w-full px-4 py-3 rounded-lg border @error('items.'.$index.'.make') border-red-500 @else border-gray-300 @enderror focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="e.g., Bayliner">
            @error("items.{$index}.make")
                <p class="text-red-600 text-sm mt-1"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>
            @enderror
        </div>

        {{-- Type/Model --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Type/Model <span class="text-red-500">*</span>
            </label>
            <input type="text" 
                wire:model.blur="items.{{ $index }}.type_model"
                class="w-full px-4 py-3 rounded-lg border @error('items.'.$index.'.type_model') border-red-500 @else border-gray-300 @enderror focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="e.g., Element 180">
            @error("items.{$index}.type_model")
                <p class="text-red-600 text-sm mt-1"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>
            @enderror
        </div>

        {{-- Year --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Year <span class="text-red-500">*</span>
            </label>
            <input type="number" 
                wire:model.blur="items.{{ $index }}.year"
                min="1900"
                max="2100"
                class="w-full px-4 py-3 rounded-lg border @error('items.'.$index.'.year') border-red-500 @else border-gray-300 @enderror focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="e.g., 2020">
            @error("items.{$index}.year")
                <p class="text-red-600 text-sm mt-1"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>
            @enderror
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

        {{-- Weight --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Weight 
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'kg' : 'lbs' }})</span>
            </label>
            <input type="number" 
                wire:model.blur="items.{{ $index }}.weight_kg"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
        </div>

        {{-- Checkboxes --}}
        <div class="lg:col-span-3 bg-cyan-100 p-3 rounded-lg space-y-2">
            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model="items.{{ $index }}.has_trailer"
                    class="w-5 h-5 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-trailer mr-1"></i>With Trailer
                </span>
            </label>
            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model="items.{{ $index }}.has_wooden_cradle"
                    class="w-5 h-5 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-tree mr-1"></i>With Wooden Cradle
                </span>
            </label>
            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model="items.{{ $index }}.has_iron_cradle"
                    class="w-5 h-5 text-cyan-600 border-gray-300 rounded focus:ring-cyan-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-industry mr-1"></i>With Iron Cradle
                </span>
            </label>
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

    </div>
    
    {{-- Overall Dimensions (separate, always visible section) --}}
    @include('livewire.commodity-forms._overall-dimensions', ['index' => $index, 'item' => $item, 'unitSystem' => $unitSystem])
</div>

