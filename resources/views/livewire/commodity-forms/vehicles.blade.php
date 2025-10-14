{{-- Vehicles Form --}}
<div class="lg:col-span-3 bg-blue-50 p-4 rounded-lg border border-blue-200">
    <h5 class="font-semibold text-blue-900 mb-3">
        <i class="fas fa-car mr-2"></i>Vehicle Details
    </h5>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        
        {{-- Vehicle Category --}}
        <div class="lg:col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Vehicle Category <span class="text-red-500">*</span>
            </label>
            <select 
                wire:model.live="items.{{ $index }}.category"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="">Select Category</option>
                @foreach(config('quotation.commodity_types.vehicles.categories') as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Make --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Make</label>
            <input type="text" 
                wire:model="items.{{ $index }}.make"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="e.g., Mercedes">
        </div>

        {{-- Type/Model --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">Type/Model</label>
            <input type="text" 
                wire:model="items.{{ $index }}.type_model"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="e.g., C-Class">
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
                @foreach(config('quotation.commodity_types.vehicles.condition_types') as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Fuel Type --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Fuel Type <span class="text-red-500">*</span>
            </label>
            <select 
                wire:model="items.{{ $index }}.fuel_type"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="">Select Fuel</option>
                @foreach(config('quotation.commodity_types.vehicles.fuel_types') as $key => $name)
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
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="450">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Width <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.width_cm"
                wire:change="calculateCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="180">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Height <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
            </label>
            <input type="number" 
                wire:model.live="items.{{ $index }}.height_cm"
                wire:change="calculateCbm({{ $index }})"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="150">
        </div>

        {{-- CBM/CuFt (Auto-calculated) --}}
        <div>
            <label class="block text-sm font-medium text-purple-700 mb-2">
                <i class="fas fa-calculator mr-1"></i>
                {{ $unitSystem === 'metric' ? 'CBM (m³)' : 'Cubic Feet (ft³)' }}
            </label>
            <input type="text" 
                value="{{ $item['cbm'] ?? '' }}"
                readonly
                class="w-full px-4 py-3 rounded-lg border-2 border-purple-400 bg-gradient-to-r from-purple-500 to-purple-600 text-white font-bold"
                placeholder="Auto-calculated">
            <p class="text-xs text-purple-600 mt-1">Auto-calculated</p>
        </div>

        {{-- Weight --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Weight 
                <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'kg' : 'lbs' }})</span>
            </label>
            <input type="number" 
                wire:model="items.{{ $index }}.weight_kg"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="1500">
        </div>

        {{-- Wheelbase (Car & SUV only) --}}
        @if(in_array($item['category'] ?? '', config('quotation.commodity_types.vehicles.has_wheelbase')))
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Wheelbase 
                    <span class="text-xs text-gray-500">({{ $unitSystem === 'metric' ? 'cm' : 'inch' }})</span>
                    @if(in_array($serviceType, config('quotation.commodity_types.vehicles.wheelbase_required_for')))
                        <span class="text-red-500">*</span>
                    @endif
                </label>
                <input type="number" 
                    wire:model="items.{{ $index }}.wheelbase_cm"
                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                    placeholder="270">
                @if(in_array($serviceType, config('quotation.commodity_types.vehicles.wheelbase_required_for')))
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-plane text-blue-500 mr-1"></i>Required for Airfreight
                    </p>
                @endif
            </div>
        @endif

        {{-- Quantity --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Quantity <span class="text-red-500">*</span>
            </label>
            <input type="number" 
                wire:model="items.{{ $index }}.quantity"
                min="1"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="1">
        </div>

        {{-- Extra Info --}}
        <div class="lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-2">Extra Info</label>
            <textarea 
                wire:model="items.{{ $index }}.extra_info"
                rows="2"
                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200" 
                placeholder="Additional information..."></textarea>
        </div>
    </div>
</div>

