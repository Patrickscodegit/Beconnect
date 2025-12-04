{{-- Machinery Form --}}
<div class="lg:col-span-3 bg-orange-50 p-4 rounded-lg border border-orange-200">
    <h5 class="font-semibold text-orange-900 mb-3">
        <i class="fas fa-cog mr-2"></i>Machinery Details
    </h5>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @if(!empty($item['category']))
        {{-- Make --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Make <span class="text-red-500">*</span>
            </label>
            <input type="text" 
                wire:model.blur="items.{{ $index }}.make"
                class="w-full px-4 py-3 rounded-lg border @error('items.'.$index.'.make') border-red-500 @else border-gray-300 @enderror focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="e.g., Caterpillar">
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
                placeholder="e.g., 320D">
            @error("items.{$index}.type_model")
                <p class="text-red-600 text-sm mt-1"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>
            @enderror
        </div>

        {{-- Condition --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Condition <span class="text-red-500">*</span>
            </label>
            <select 
                wire:model="items.{{ $index }}.condition"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="">Select Condition</option>
                @foreach(config('quotation.commodity_types.machinery.condition_types') as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
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

        {{-- Fuel Type --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">Fuel Type</label>
            <select 
                wire:model="items.{{ $index }}.fuel_type"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="">Select Fuel</option>
                @foreach(config('quotation.commodity_types.machinery.fuel_types') as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
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

        {{-- Has Parts Checkbox --}}
        <div class="lg:col-span-3 bg-orange-100 p-3 rounded-lg">
            <label class="flex items-center cursor-pointer">
                <input type="checkbox" 
                    wire:model.live="items.{{ $index }}.has_parts"
                    class="w-5 h-5 text-orange-600 border-gray-300 rounded focus:ring-orange-500">
                <span class="ml-3 text-sm font-medium text-gray-700">
                    <i class="fas fa-puzzle-piece mr-1"></i>Has Parts
                </span>
            </label>
        </div>

        {{-- Parts Description (conditional) --}}
        @if($item['has_parts'] ?? false)
            <div class="lg:col-span-3">
                <label class="block text-sm font-medium text-gray-700 mb-2">Parts Description</label>
                <textarea 
                    wire:model="items.{{ $index }}.parts_description"
                    rows="3"
                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                    placeholder="Describe the parts..."></textarea>
            </div>
        @endif

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

        @include('livewire.commodity-forms._stack-dimensions', ['index' => $index, 'item' => $item, 'unitSystem' => $unitSystem])
        @endif
    </div>
</div>

