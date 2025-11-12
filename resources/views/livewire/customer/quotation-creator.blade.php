<div>
    {{-- Auto-save indicator --}}
    <div class="mb-4 flex justify-between items-center">
        <div>
            <span class="text-sm text-gray-600">
                <i wire:loading.remove class="fas fa-check-circle text-green-500 mr-1"></i>
                <i wire:loading class="fas fa-sync fa-spin text-blue-500 mr-1"></i>
                <span wire:loading.remove>Draft saved automatically</span>
                <span wire:loading>Saving...</span>
            </span>
        </div>
        @if($quotationId)
            <div class="text-xs text-gray-500">
                Draft ID: QR-{{ str_pad($quotationId, 4, '0', STR_PAD_LEFT) }}
            </div>
        @endif
    </div>

    @php
        $isAir = $isAirService ?? false;
        $portsDisabled = !($portsEnabled ?? false);
        $polLabel = $isAir ? 'Airport of Departure (POL)' : 'Port of Loading (POL)';
        $podLabel = $isAir ? 'Airport of Arrival (POD)' : 'Port of Discharge (POD)';
        $locationHelp = $isAir ? 'airport' : 'port';
    @endphp

    {{-- Service Selection --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-4">
            <i class="fas fa-shipping-fast mr-2"></i>Select Service Type
        </h2>
        <p class="text-sm text-gray-600 mb-6">
            Choose the transport mode for this quotation. Routes and optional services will adapt automatically.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            @foreach($serviceTypes as $key => $type)
                @php
                    $typeIsArray = is_array($type);
                    $label = $typeIsArray ? ($type['name'] ?? $key) : $type;
                    $description = $typeIsArray ? ($type['description'] ?? '') : '';
                    $icon = $typeIsArray ? ($type['icon'] ?? '') : '';
                    $isSelected = $simple_service_type === $key;
                @endphp
                <label wire:key="service-type-{{ $key }}"
                       class="relative flex items-start p-4 border-2 rounded-xl cursor-pointer transition-all {{ $isSelected ? 'border-blue-500 bg-blue-50 shadow-sm' : 'border-gray-200 bg-white hover:border-blue-300 hover:bg-blue-50/60' }}">
                    <input type="radio"
                           class="sr-only"
                           wire:model.live="simple_service_type"
                           value="{{ $key }}">
                    <div class="flex items-center space-x-4">
                        @if($icon)
                            <span class="text-2xl leading-none">{{ $icon }}</span>
                        @else
                            <span class="text-2xl leading-none text-blue-500">
                                <i class="fas fa-route"></i>
                            </span>
                        @endif
                        <div>
                            <div class="font-semibold text-gray-900">{{ $label }}</div>
                            @if($description)
                                <p class="text-sm text-gray-600 mt-1">{{ $description }}</p>
                            @endif
                        </div>
                    </div>
                    @if($isSelected)
                        <span class="absolute top-3 right-3 text-blue-500">
                            <i class="fas fa-check-circle"></i>
                        </span>
                    @endif
                </label>
            @endforeach
        </div>
        @error('simple_service_type') <span class="text-red-500 text-xs mt-3 block">{{ $message }}</span> @enderror
    </div>

    {{-- Route Section --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-route mr-2"></i>Route Details
        </h2>

        @if($portsDisabled)
            <div class="mb-6 rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800">
                <i class="fas fa-info-circle mr-2"></i>Select a service type above to unlock the route fields.
            </div>
        @endif

        <div class="{{ $portsDisabled ? 'opacity-50 pointer-events-none select-none' : '' }}">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- POL --}}
                <div wire:ignore>
                    <label for="pol" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ $polLabel }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           id="pol"
                           class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                           placeholder="{{ $polPlaceholder }}"
                           @disabled($portsDisabled)
                           required>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle"></i> Type to search, or enter a custom {{ $locationHelp }} name
                    </p>
                    @error('pol') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- POD --}}
                <div wire:ignore>
                    <label for="pod" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ $podLabel }} <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           id="pod"
                           class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                           placeholder="{{ $podPlaceholder }}"
                           @disabled($portsDisabled)
                           required>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-info-circle"></i> Only {{ $locationHelp }}s with matching schedules will appear first. You can still enter a custom value.
                    </p>
                    @error('pod') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- POR (Optional) --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Place of Receipt (POR) <span class="text-gray-400 text-xs">(Optional)</span>
                    </label>
                    <input type="text"
                           wire:model.debounce.500ms="por"
                           class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                           placeholder="e.g., Brussels"
                           @disabled($portsDisabled)>
                </div>

                {{-- FDEST (Optional) --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Final Destination (FDEST) <span class="text-gray-400 text-xs">(Optional)</span>
                    </label>
                    <input type="text"
                           wire:model.debounce.500ms="fdest"
                           class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                           placeholder="e.g., Bamako"
                           @disabled($portsDisabled)>
                </div>
            </div>
        </div>
    </div>

    {{-- Schedule Selection --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-calendar-alt mr-2"></i>
            @if($isAir)
                Flight & Handling Schedule
                <span class="text-sm font-normal text-gray-500">(Optional for airfreight)</span>
            @else
                Select Sailing Schedule
                <span class="text-sm font-normal text-gray-500">(Required for pricing & article suggestions)</span>
            @endif
        </h2>

        {{-- Debug info (temporary) - Always visible to diagnose issues --}}
        <div class="mb-4 text-xs text-gray-400 bg-gray-50 p-2 rounded border border-gray-200">
            <strong>Debug:</strong> POL: "{{ $pol }}" | POD: "{{ $pod }}" | POL filled: {{ !empty(trim($pol)) ? 'YES' : 'NO' }} | POD filled: {{ !empty(trim($pod)) ? 'YES' : 'NO' }} | Schedule ID: {{ $selected_schedule_id ?? 'null' }} | Commodity Type: "{{ $this->getEffectiveCommodityType() ?: 'NONE' }}" | Mode: {{ $quotationMode }} | Service: {{ $simple_service_type ?? 'NONE' }} | Show Articles: {{ $showArticles ? 'YES' : 'NO' }} | Schedules found: {{ $schedules->count() ?? 0 }}
        </div>

        @if($isAir)
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                <i class="fas fa-info-circle mr-2"></i>Airfreight quotations do not require a sailing schedule. Our team will confirm flights and handling windows separately.
            </div>
        @else
            @if($pol && $pod)
                <select wire:model="selected_schedule_id"
                        wire:change="$refresh"
                        wire:key="schedule-select-{{ md5($pol) }}-{{ md5($pod) }}"
                        class="form-select w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                    <option value="">-- Select a Sailing --</option>
                    @foreach($schedules as $schedule)
                        <option value="{{ $schedule->id }}" {{ $selected_schedule_id == $schedule->id ? 'selected' : '' }}>
                            {{ $schedule->carrier->name ?? 'Unknown Carrier' }} -
                            Departure: {{ $schedule->ets_pol ? $schedule->ets_pol->format('M d, Y') : ($schedule->next_sailing_date ? $schedule->next_sailing_date->format('M d, Y') : 'TBA') }}
                            @if($schedule->eta_pod)
                                - Arrival: {{ $schedule->eta_pod->format('M d, Y') }}
                            @endif
                        </option>
                    @endforeach
                </select>

                @if($schedules->count() === 0)
                    <p class="text-sm text-gray-500 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        No scheduled sailings found for this route. You can still submit your request.
                    </p>
                @endif
            @else
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                    <i class="fas fa-arrow-up text-gray-400 text-3xl mb-2"></i>
                    <p class="text-gray-600">Please select POL and POD first to see available sailings</p>
                </div>
            @endif
        @endif
    </div>
    
    {{-- Cargo Information --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6"
         x-data="{ quotationMode: @entangle('quotationMode') }">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-box mr-2"></i>Cargo Information
        </h2>
        
        {{-- Toggle Between Quick Quote and Detailed Quote --}}
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-lg border-2 border-blue-200 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-exchange-alt mr-2 text-blue-600"></i>Choose Your Input Method
            </h3>
            
            <div class="space-y-3">
                {{-- Quick Quote Option --}}
                <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer transition-all"
                       :class="quotationMode === 'quick' ? 'border-blue-500 bg-blue-50' : 'border-gray-300 bg-white hover:border-blue-300'"
                       wire:ignore>
                    <input type="radio" 
                           x-model="quotationMode" 
                           value="quick" 
                           class="mt-1 mr-3 w-5 h-5 text-blue-600"
                           @change="$wire.set('quotationMode', 'quick')">
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">
                            <i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Quote
                        </div>
                        <p class="text-sm text-gray-600 mt-1">Fast entry for simple shipments with basic cargo details</p>
                    </div>
                </label>
                
                {{-- Detailed Quote Option --}}
                <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer transition-all"
                       :class="quotationMode === 'detailed' ? 'border-green-500 bg-green-50' : 'border-gray-300 bg-white hover:border-green-300'"
                       wire:ignore>
                    <input type="radio" 
                           x-model="quotationMode" 
                           value="detailed" 
                           class="mt-1 mr-3 w-5 h-5 text-green-600"
                           @change="$wire.set('quotationMode', 'detailed')">
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900">
                            <i class="fas fa-cubes text-green-600 mr-2"></i>Detailed Quote
                            <span class="ml-2 bg-green-500 text-white px-2 py-0.5 rounded-full text-xs font-semibold">RECOMMENDED</span>
                        </div>
                        <p class="text-sm text-gray-600 mt-1">Multi-commodity breakdown for most accurate pricing and faster processing</p>
                    </div>
                </label>
            </div>
        </div>
        
        {{-- Quick Quote Form --}}
        <div x-show="quotationMode === 'quick'" x-cloak>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Commodity Type <span class="text-red-500">*</span>
                    </label>
                    <select wire:model="commodity_type" 
                            wire:change="$refresh"
                            class="form-select w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                            required>
                        <option value="">-- Select Commodity Type --</option>
                        <option value="cars">Cars</option>
                        <option value="trucks">Trucks</option>
                        <option value="machinery">Machinery</option>
                        <option value="general_goods">General Goods</option>
                        <option value="personal_goods">Personal Goods</option>
                        <option value="motorcycles">Motorcycles</option>
                        <option value="breakbulk">Break Bulk</option>
                    </select>
                    @error('commodity_type') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Cargo Description <span class="text-red-500">*</span>
                    </label>
                    <textarea wire:model.debounce.500ms="cargo_description"
                              rows="4"
                              class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                              placeholder="Describe your cargo in detail (e.g., 1x Toyota Corolla 2020, white, running condition)"
                              required></textarea>
                    @error('cargo_description') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Weight (kg) <span class="text-gray-400 text-xs">(Optional)</span>
                        </label>
                        <input type="number" 
                               wire:model.debounce.500ms="cargo_weight"
                               step="0.01" 
                               min="0"
                               class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                               placeholder="e.g., 1500">
                        @error('cargo_weight') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Volume (m³) <span class="text-gray-400 text-xs">(Optional)</span>
                        </label>
                        <input type="number" 
                               wire:model.debounce.500ms="cargo_volume"
                               step="0.01" 
                               min="0"
                               class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                               placeholder="e.g., 25.5">
                        @error('cargo_volume') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Dimensions <span class="text-gray-400 text-xs">(Optional)</span>
                        </label>
                        <input type="text" 
                               wire:model.debounce.500ms="cargo_dimensions"
                               class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                               placeholder="L x W x H (cm)">
                        @error('cargo_dimensions') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Special Requirements <span class="text-gray-400 text-xs">(Optional)</span>
                    </label>
                    <textarea wire:model.debounce.500ms="special_requirements"
                              rows="3"
                              class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                              placeholder="Any special handling, insurance, or documentation requirements..."></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Customer Reference <span class="text-gray-400 text-xs">(Optional)</span>
                    </label>
                    <input type="text" 
                           wire:model.debounce.500ms="customer_reference"
                           class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                           placeholder="Your internal reference number">
                </div>
            </div>
        </div>
        
        {{-- Detailed Quote Form (Multi-Commodity Items) --}}
        <div x-show="quotationMode === 'detailed'" x-cloak>
            @php
                // Ensure quotation has commodityItems relationship loaded
                $existingItems = [];
                $serviceTypeForRepeater = '';
                
                if ($quotation) {
                    try {
                        // Load relationship safely
                        $quotation = $quotation->fresh(['commodityItems']);
                        
                        // Convert collection to array format expected by component
                        if ($quotation->commodityItems && $quotation->commodityItems->isNotEmpty()) {
                            $existingItems = $quotation->commodityItems->map(function ($item) {
                                // Convert model to array, removing timestamps and relations
                                $array = $item->toArray();
                                // Remove timestamps and relationship keys
                                unset($array['created_at'], $array['updated_at'], $array['quotation_request_id']);
                                return $array;
                            })->toArray();
                        }
                        
                        // Ensure serviceType is set, default to service_type or empty string
                        $serviceTypeForRepeater = $service_type ?: ($quotation->service_type ?? '');
                    } catch (\Exception $e) {
                        // If there's an error loading, start with empty array
                        \Log::error('Error loading commodity items for quotation', [
                            'quotation_id' => $quotation->id ?? null,
                            'error' => $e->getMessage()
                        ]);
                        $existingItems = [];
                        $serviceTypeForRepeater = $service_type ?: '';
                    }
                } else {
                    // No quotation yet, use empty array
                    $serviceTypeForRepeater = $service_type ?: '';
                }
            @endphp
            @livewire('commodity-items-repeater', [
                'quotationId' => $quotation->id ?? null,
                'existingItems' => $existingItems,
                'serviceType' => $serviceTypeForRepeater,
                'unitSystem' => 'metric'
            ], key('commodity-repeater-' . ($quotation->id ?? 'new')))
        </div>
    </div>
    
    {{-- SMART ARTICLE SELECTOR - Shows when POL+POD+Schedule+Commodity selected --}}
    @if($showArticles && $quotation)
        <div class="bg-white rounded-lg shadow p-8 mb-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">
                <i class="fas fa-lightbulb mr-2 text-yellow-500"></i>Suggested Services & Pricing
            </h2>
            
            <p class="text-gray-600 mb-4">
                Based on your route ({{ $pol }} → {{ $pod }}) and selected schedule, we suggest these services:
            </p>
            
            @php
                // Ensure quotation is fresh with relationships
                $freshQuotation = $quotation->fresh(['selectedSchedule.carrier']);
            @endphp
            @livewire('smart-article-selector', [
                'quotation' => $freshQuotation,
                'showPricing' => true,
                'isEditable' => true
            ], key('article-selector-' . $quotation->id))
        </div>
    @elseif($pol && $pod && $selected_schedule_id && !$commodity_type)
        {{-- Prompt to select commodity type --}}
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
            <div class="flex items-start">
                <i class="fas fa-info-circle text-yellow-600 text-3xl mr-4"></i>
                <div>
                    <h3 class="text-lg font-semibold text-yellow-900 mb-2">Select Commodity Type</h3>
                    <p class="text-sm text-yellow-800">
                        To see suggested services and get instant pricing, please select a commodity type from the section above.
                    </p>
                    <p class="text-sm text-yellow-700 mt-2">
                        💡 <strong>Tip:</strong> Selecting your commodity type helps us show the most relevant articles and accurate pricing for your shipment.
                    </p>
                </div>
            </div>
        </div>
    @elseif($pol && $pod && !$selected_schedule_id)
        {{-- Prompt to select schedule --}}
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
            <div class="flex items-start">
                <i class="fas fa-arrow-up text-yellow-600 text-3xl mr-4"></i>
                <div>
                    <h3 class="text-lg font-semibold text-yellow-900 mb-2">Select a Sailing Schedule Above</h3>
                    <p class="text-sm text-yellow-800">
                        To see suggested services and get instant pricing, please select a sailing schedule from the section above.
                    </p>
                    <p class="text-sm text-yellow-700 mt-2">
                        💡 <strong>Tip:</strong> Selecting a schedule helps us suggest the most accurate articles and pricing for your shipment.
                    </p>
                </div>
            </div>
        </div>
    @endif
    
    {{-- Selected Articles Summary --}}
    @if($quotation && $quotation->articles->count() > 0)
        <div class="bg-white rounded-lg shadow p-8 mb-6">
            <h3 class="text-xl font-semibold text-gray-900 mb-4">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>
                Selected Services ({{ $quotation->articles->count() }})
            </h3>
            
            <div class="space-y-3 mb-4">
                @foreach($quotation->articles as $article)
                    <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div class="flex-1">
                            <p class="font-medium text-gray-900">{{ $article->description }}</p>
                            <p class="text-sm text-gray-600 mt-1">
                                Code: {{ $article->article_code }}
                                @if($article->pivot->quantity && $article->pivot->unit_price)
                                    <br>
                                    Qty: {{ $article->pivot->quantity }} × 
                                    €{{ number_format($article->pivot->unit_price, 2) }} = 
                                    <span class="font-semibold text-blue-600">€{{ number_format($article->pivot->quantity * $article->pivot->unit_price, 2) }}</span>
                                @endif
                            </p>
                        </div>
                        <button type="button"
                                wire:click="$dispatch('removeArticle', { articleId: {{ $article->id }} })"
                                class="text-red-600 hover:text-red-800 ml-4 p-2">
                            <i class="fas fa-times text-lg"></i>
                        </button>
                    </div>
                @endforeach
            </div>
            
            {{-- Pricing Summary --}}
            <div class="mt-6 pt-6 border-t border-gray-200 bg-blue-50 rounded-lg p-4">
                <div class="flex justify-between text-sm mb-2">
                    <span class="text-gray-700">Subtotal:</span>
                    <span class="font-semibold text-gray-900">€{{ number_format($quotation->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between text-sm mb-2">
                    <span class="text-gray-700">VAT ({{ $quotation->vat_rate }}%):</span>
                    <span class="font-semibold text-gray-900">€{{ number_format($quotation->vat_amount, 2) }}</span>
                </div>
                <div class="flex justify-between text-lg font-bold pt-3 border-t border-gray-300">
                    <span class="text-gray-900">Total (incl. VAT):</span>
                    <span class="text-blue-600">€{{ number_format($quotation->total_incl_vat, 2) }}</span>
                </div>
                <p class="text-xs text-gray-500 mt-2 text-center">
                    <i class="fas fa-info-circle mr-1"></i>
                    Prices are estimates and subject to final confirmation by our team
                </p>
            </div>
        </div>
    @endif
    
    {{-- File Uploads --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-paperclip mr-2"></i>Supporting Documents
            <span class="text-sm font-normal text-gray-500">(Optional)</span>
        </h2>
        
        <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-400 transition">
            <input type="file" 
                   wire:model="supporting_files" 
                   multiple
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                   class="hidden"
                   id="file-upload">
            
            <label for="file-upload" class="cursor-pointer">
                <i class="fas fa-cloud-upload-alt text-5xl text-gray-400 mb-3"></i>
                <p class="text-gray-600 font-medium">Click to upload or drag and drop</p>
                <p class="text-sm text-gray-500 mt-2">
                    PDF, Images, Office documents (max 10MB each, up to 5 files)
                </p>
            </label>
        </div>
        
        @error('supporting_files.*') 
            <span class="text-red-500 text-sm mt-2 block">{{ $message }}</span> 
        @enderror
        
        <div wire:loading wire:target="supporting_files" class="mt-3 text-sm text-blue-600">
            <i class="fas fa-spinner fa-spin mr-1"></i> Uploading files...
        </div>
    </div>
    
    {{-- Validation Errors --}}
    @error('commodity_items')
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <i class="fas fa-exclamation-circle text-red-600 mr-3 mt-1"></i>
                <div>
                    <h3 class="text-lg font-semibold text-red-900 mb-1">Validation Error</h3>
                    <p class="text-sm text-red-800">{{ $message }}</p>
                </div>
            </div>
        </div>
    @enderror
    
    {{-- Action Buttons --}}
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-8">
        <button type="button" 
                wire:click="saveDraft"
                class="w-full sm:w-auto px-6 py-3 border border-gray-300 rounded-lg hover:bg-gray-50 transition font-medium text-gray-700">
            <i class="fas fa-save mr-2"></i>Save Draft
        </button>
        
        <button type="button" 
                wire:click="submit"
                wire:loading.attr="disabled"
                class="w-full sm:w-auto px-8 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="submit">
                <i class="fas fa-paper-plane mr-2"></i>Submit for Review
            </span>
            <span wire:loading wire:target="submit">
                <i class="fas fa-spinner fa-spin mr-2"></i>Submitting...
            </span>
        </button>
    </div>
    
    {{-- Success/Info Messages --}}
    @if (session()->has('message'))
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>
                <p class="text-sm text-green-800">{{ session('message') }}</p>
            </div>
        </div>
    @endif
    
    {{-- Info Box --}}
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-blue-600 text-2xl mr-3"></i>
            <div>
                <h4 class="font-semibold text-blue-900 mb-2">What happens next?</h4>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>✓ Your quotation will be reviewed by our Belgaco team</li>
                    <li>✓ We'll respond within 24 hours</li>
                    <li>✓ You'll receive a detailed quotation via email</li>
                    <li>✓ You can view and track progress in your dashboard</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const state = {
        polOptions: @json($polPortsFormatted),
        podOptions: @json($podPortsFormatted),
        polPlaceholder: @json($polPlaceholder),
        podPlaceholder: @json($podPlaceholder),
        portsEnabled: @json($portsEnabled),
    };

    const dropdowns = {};
    const MAX_RESULTS = 10;

    function log(...args) {
        if (window?.console) {
            console.log('🔵 Autocomplete:', ...args);
        }
    }

    function getInput(id) {
        return document.getElementById(id);
    }

    function getOptions(field) {
        return field === 'pol' ? state.polOptions || {} : state.podOptions || {};
    }

    function ensureDropdown(input) {
        const parent = input.parentElement;
        if (!parent) {
            return null;
        }

        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }

        let dropdown = parent.querySelector('.autocomplete-dropdown');
        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.className = 'autocomplete-dropdown absolute z-50 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden';
            dropdown.style.width = input.offsetWidth + 'px';
            parent.appendChild(dropdown);
        }

        return dropdown;
    }

    function syncValueToLivewire(fieldType, selectedValue) {
        let component = null;
        let element = document.getElementById(fieldType);

        while (element && element !== document.body) {
            if (element.hasAttribute && element.hasAttribute('wire:id')) {
                const componentId = element.getAttribute('wire:id');
                if (window.Livewire) {
                    try {
                        component = window.Livewire.find(componentId);
                    } catch (e) {
                        console.warn('Could not find Livewire component:', e);
                    }
                }
                break;
            }
            element = element.parentElement;
        }

        if (!component) {
            console.warn('⚠️ Autocomplete: Livewire component not found for', fieldType);
            return;
        }

        try {
            if (component.call && typeof component.call === 'function') {
                component.call('setPort', fieldType, selectedValue);
            } else {
                component.set(fieldType, selectedValue);
                if (window.Livewire && window.Livewire.dispatch) {
                    window.Livewire.dispatch('port-updated', {
                        field: fieldType,
                        value: selectedValue,
                    });
                }

                setTimeout(() => {
                    if (component.$refresh) {
                        component.$refresh();
                    }
                }, 100);
            }
        } catch (error) {
            console.error('🔴 Autocomplete: Failed to sync with Livewire', error);
        }
    }

    function setupAutocomplete(input) {
        if (!input) {
            return;
        }

        const dropdown = ensureDropdown(input);
        if (!dropdown) {
            return;
        }

        const fieldType = input.id;
        let isFocused = false;
        let isSelecting = false;

        function renderDropdown(query = '', force = false) {
            if (!state.portsEnabled) {
                dropdown.classList.add('hidden');
                return;
            }

            // Only show dropdown if input is focused (unless forced)
            if (!force && !isFocused) {
                dropdown.classList.add('hidden');
                return;
            }

            const options = getOptions(fieldType);
            const lowerQuery = (query || '').toLowerCase().trim();
            let matches = Object.entries(options);

            if (lowerQuery.length > 0) {
                matches = matches.filter(([key, value]) =>
                    key.toLowerCase().includes(lowerQuery) || value.toLowerCase().includes(lowerQuery)
                );
            }

            matches = matches.slice(0, MAX_RESULTS);

            if (!force && lowerQuery.length === 0) {
                dropdown.classList.add('hidden');
                dropdown.innerHTML = '';
                return;
            }

            if (matches.length === 0 && lowerQuery.length > 0) {
                dropdown.innerHTML = `
                    <div class="px-4 py-3 text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-2"></i>
                        No matches found. Press Enter to use "${query}" as a custom value.
                    </div>
                `;
                dropdown.classList.remove('hidden');
            } else if (matches.length > 0) {
                dropdown.innerHTML = matches.map(([key]) => `
                    <div class="px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0"
                         data-value="${key}">
                        <div class="font-medium text-gray-900">${key}</div>
                    </div>
                `).join('');
                dropdown.classList.remove('hidden');

                dropdown.querySelectorAll('[data-value]').forEach(item => {
                    item.addEventListener('click', function () {
                        isSelecting = true;
                        const selectedValue = this.dataset.value;
                        input.value = selectedValue;
                        dropdown.classList.add('hidden');
                        syncValueToLivewire(fieldType, selectedValue);
                        // Don't trigger blur - just remove focus state
                        isFocused = false;
                        setTimeout(() => { isSelecting = false; }, 100);
                    }, { once: true });
                });
            } else {
                dropdown.classList.add('hidden');
                dropdown.innerHTML = '';
            }
        }

        dropdowns[fieldType] = { input, dropdown, renderDropdown };

        if (!input.dataset.autocompleteBound) {
            input.addEventListener('focus', function () {
                isFocused = true;
                renderDropdown(this.value, true);
            });

            input.addEventListener('click', function () {
                isFocused = true;
                renderDropdown(this.value, true);
            });

            input.addEventListener('input', function () {
                if (isFocused) {
                    renderDropdown(this.value, true);
                }
            });

            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && this.value.trim()) {
                    dropdown.classList.add('hidden');
                    event.preventDefault();
                    isFocused = false;
                    this.blur();
                }
            });

            input.addEventListener('blur', function () {
                // Don't close if user is selecting from dropdown
                if (isSelecting) {
                    return;
                }
                isFocused = false;
                setTimeout(() => {
                    if (!isFocused && !isSelecting) {
                        dropdown.classList.add('hidden');
                    }
                }, 150);
            });

            document.addEventListener('click', function (event) {
                if (!dropdown.contains(event.target) && event.target !== input) {
                    dropdown.classList.add('hidden');
                    isFocused = false;
                }
            });

            input.dataset.autocompleteBound = 'true';
        }

        // Don't render dropdown on initialization - wait for user interaction
        dropdown.classList.add('hidden');
    }

    function updatePlaceholdersAndDisabled() {
        const polInput = getInput('pol');
        const podInput = getInput('pod');

        if (polInput) {
            if (state.polPlaceholder) {
                polInput.placeholder = state.polPlaceholder;
            }
            polInput.disabled = !state.portsEnabled;
        }

        if (podInput) {
            if (state.podPlaceholder) {
                podInput.placeholder = state.podPlaceholder;
            }
            podInput.disabled = !state.portsEnabled;
        }
    }

    function refreshDropdowns() {
        Object.values(dropdowns).forEach(({ renderDropdown, input, dropdown }) => {
            // Only refresh if dropdown is currently open (user is interacting)
            const isOpen = dropdown && !dropdown.classList.contains('hidden');
            if (isOpen) {
                renderDropdown(input.value, true);
            }
        });
    }

    function reinitialize() {
        setupAutocomplete(getInput('pol'));
        setupAutocomplete(getInput('pod'));
        updatePlaceholdersAndDisabled();
        refreshDropdowns();
    }

    function handlePortEvent(payload = {}) {
        if (payload.polOptions !== undefined) {
            state.polOptions = payload.polOptions || {};
        }
        if (payload.podOptions !== undefined) {
            state.podOptions = payload.podOptions || {};
        }
        if (payload.polPlaceholder !== undefined) {
            state.polPlaceholder = payload.polPlaceholder;
        }
        if (payload.podPlaceholder !== undefined) {
            state.podPlaceholder = payload.podPlaceholder;
        }
        if (payload.portsEnabled !== undefined) {
            state.portsEnabled = !!payload.portsEnabled;
        }

        requestAnimationFrame(() => {
            reinitialize();

            if (!state.portsEnabled) {
                Object.values(dropdowns).forEach(({ dropdown }) => dropdown.classList.add('hidden'));
            }
        });
    }

    function registerLivewireListener() {
        if (window.Livewire && typeof window.Livewire.on === 'function') {
            window.Livewire.on('quotation-ports-updated', handlePortEvent);
            return true;
        }
        return false;
    }

    function init() {
        updatePlaceholdersAndDisabled();
        reinitialize();
    }

    if (!window.__quotationPortsListenerRegistered) {
        window.__quotationPortsListenerRegistered = registerLivewireListener();
    }

    if (!window.__quotationPortsListenerRegistered) {
        document.addEventListener('livewire:load', () => {
            if (!window.__quotationPortsListenerRegistered) {
                window.__quotationPortsListenerRegistered = registerLivewireListener();
            }
            init();
        }, { once: true });
    } else {
        init();
    }

    document.addEventListener('livewire:navigated', () => {
        init();
    });

    window.addEventListener('quotation-ports-updated', event => {
        handlePortEvent(event.detail || {});
    });
})();
</script>
