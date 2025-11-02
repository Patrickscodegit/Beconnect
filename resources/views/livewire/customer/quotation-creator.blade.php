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

    {{-- Route & Service Section --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-route mr-2"></i>Route & Service
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- POL --}}
            <div>
                <label for="pol" class="block text-sm font-medium text-gray-700 mb-2">
                    Port of Loading (POL) <span class="text-red-500">*</span>
                </label>
                <input type="text" 
                       id="pol"
                       wire:model.debounce.500ms="pol"
                       class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                       placeholder="Search or type any port..."
                       required>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle"></i> Type to search, or enter a custom port name
                </p>
                @error('pol') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
            </div>
            
            {{-- POD --}}
            <div>
                <label for="pod" class="block text-sm font-medium text-gray-700 mb-2">
                    Port of Discharge (POD) <span class="text-red-500">*</span>
                </label>
                <input type="text" 
                       id="pod"
                       wire:model.debounce.500ms="pod"
                       class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                       placeholder="Search or type any port..."
                       required>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle"></i> Type to search, or enter a custom port name
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
                       placeholder="e.g., Brussels">
            </div>
            
            {{-- FDEST (Optional) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Final Destination (FDEST) <span class="text-gray-400 text-xs">(Optional)</span>
                </label>
                <input type="text" 
                       wire:model.debounce.500ms="fdest"
                       class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                       placeholder="e.g., Bamako">
            </div>
            
            {{-- Service Type --}}
            <div class="col-span-1 md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Service Type <span class="text-red-500">*</span>
                </label>
                <select wire:model="simple_service_type" 
                        class="form-select w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                        required>
                    <option value="">-- Select Service Type --</option>
                    @foreach($serviceTypes as $key => $type)
                        <option value="{{ $key }}">{{ is_array($type) ? $type['name'] : $type }}</option>
                    @endforeach
                </select>
                @error('simple_service_type') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>
    
    {{-- Schedule Selection --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-calendar-alt mr-2"></i>Select Sailing Schedule
            <span class="text-sm font-normal text-gray-500">(Required for pricing & article suggestions)</span>
        </h2>
        
        {{-- Debug info (temporary) - Always visible to diagnose issues --}}
        <div class="mb-4 text-xs text-gray-400 bg-gray-50 p-2 rounded border border-gray-200">
            <strong>Debug:</strong> POL: "{{ $pol }}" | POD: "{{ $pod }}" | POL filled: {{ !empty(trim($pol)) ? 'YES' : 'NO' }} | POD filled: {{ !empty(trim($pod)) ? 'YES' : 'NO' }} | Schedule ID: {{ $selected_schedule_id ?? 'null' }} | Show Articles: {{ $showArticles ? 'YES' : 'NO' }} | Schedules found: {{ $schedules->count() ?? 0 }}
        </div>
        
        @if($pol && $pod)
            <select wire:model="selected_schedule_id" 
                    class="form-select w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                <option value="">-- Select a Sailing --</option>
                @foreach($schedules as $schedule)
                    <option value="{{ $schedule->id }}">
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
    </div>
    
    {{-- Cargo Information --}}
    <div class="bg-white rounded-lg shadow p-8 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-box mr-2"></i>Cargo Information
        </h2>
        
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Commodity Type <span class="text-red-500">*</span>
                </label>
                <select wire:model="commodity_type" 
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
    
    {{-- SMART ARTICLE SELECTOR - Shows when POL+POD+Schedule selected --}}
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
// Initialize searchable port selects with custom input
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔵 Autocomplete: DOMContentLoaded - initializing autocomplete');
    
    // Port data from backend
    const polSeaports = @json($polPortsFormatted);
    const podSeaports = @json($podPortsFormatted);
    
    console.log('🔵 Autocomplete: Port data loaded', { polCount: Object.keys(polSeaports).length, podCount: Object.keys(podSeaports).length });
    
    const polInput = document.getElementById('pol');
    const podInput = document.getElementById('pod');
    
    console.log('🔵 Autocomplete: Inputs found', { polInput: !!polInput, podInput: !!podInput });
    
    // Initialize autocomplete for POL and POD
    if (polInput && podInput) {
        console.log('🔵 Autocomplete: Setting up autocomplete for POL and POD');
        // Function to get current port list based on field type
        function getCurrentPortList(fieldType) {
            return fieldType === 'pol' ? polSeaports : podSeaports;
        }
        
        // Setup autocomplete for an input field
        function setupAutocomplete(input) {
            // IMPORTANT: Don't wrap or move the input! This breaks Livewire's event listeners.
            // Instead, make the parent relative and position dropdown absolutely
            const parent = input.parentElement;
            if (!parent) {
                console.error('🔴 Autocomplete: Input has no parent element');
                return;
            }
            
            // Check if autocomplete already set up for this input
            const existingDropdown = parent.querySelector('.autocomplete-dropdown');
            if (existingDropdown) {
                console.log(`🔵 Autocomplete: ${input.id} already has dropdown, skipping setup`);
                return;
            }
            
            // Mark input as having autocomplete set up
            input.dataset.autocompleteSetup = 'true';
            
            // Make parent relative if not already
            if (getComputedStyle(parent).position === 'static') {
                parent.style.position = 'relative';
            }
            
            const dropdown = document.createElement('div');
            dropdown.className = 'autocomplete-dropdown absolute z-50 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden';
            dropdown.style.width = input.offsetWidth + 'px';
            parent.appendChild(dropdown);
            
            // Function to render dropdown with matches
            function renderDropdown(query = '') {
                const fieldType = input.id; // 'pol' or 'pod'
                console.log(`🔵 Autocomplete: renderDropdown called for ${fieldType} with query: "${query}"`);
                
                const portList = getCurrentPortList(fieldType);
                const lowerQuery = query.toLowerCase().trim();
                
                console.log(`🔵 Autocomplete: Port list size for ${fieldType}: ${Object.keys(portList).length}`);
                
                // Filter matching ports (or show all if no query)
                let matches = Object.entries(portList);
                if (lowerQuery.length > 0) {
                    matches = matches.filter(([key, value]) => 
                        key.toLowerCase().includes(lowerQuery) || value.toLowerCase().includes(lowerQuery)
                    );
                }
                matches = matches.slice(0, 10);
                
                console.log(`🔵 Autocomplete: ${fieldType} - Found ${matches.length} matches`);
                
                if (matches.length === 0 && lowerQuery.length > 0) {
                    dropdown.innerHTML = `
                        <div class="px-4 py-3 text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-2"></i>
                            No matches found. Press Enter to use "${query}" as a custom port.
                        </div>
                    `;
                    dropdown.classList.remove('hidden');
                    console.log(`🔵 Autocomplete: ${fieldType} - Showing "no matches" dropdown`);
                } else if (matches.length > 0) {
                    dropdown.innerHTML = matches.map(([key, value]) => `
                        <div class="px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0" 
                             data-value="${key}">
                            <div class="font-medium text-gray-900">${key}</div>
                        </div>
                    `).join('');
                    dropdown.classList.remove('hidden');
                    console.log(`✅ Autocomplete: ${fieldType} - Dropdown shown with ${matches.length} items`);
                    
                    // Add click handlers for items
                    dropdown.querySelectorAll('[data-value]').forEach(item => {
                        item.addEventListener('click', function() {
                            const selectedValue = this.dataset.value;
                            const fieldType = input.id; // 'pol' or 'pod'
                            
                            console.log(`🔵 Autocomplete: Item clicked - ${fieldType} = "${selectedValue}"`);
                            
                            input.value = selectedValue;
                            dropdown.classList.add('hidden');
                            
                            // Method 1: Dispatch input event (Livewire should detect this)
                            console.log(`🔵 Autocomplete: Dispatching input event for ${fieldType}`);
                            const inputEvent = new Event('input', { bubbles: true, cancelable: true });
                            input.dispatchEvent(inputEvent);
                            
                            // Method 2: Also try Livewire's $wire API directly
                            // Find the Livewire component by traversing up from input
                            let component = null;
                            let element = input;
                            
                            // Traverse up to find wire:id
                            while (element && element !== document.body) {
                                if (element.hasAttribute && element.hasAttribute('wire:id')) {
                                    const componentId = element.getAttribute('wire:id');
                                    if (window.Livewire) {
                                        try {
                                            component = window.Livewire.find(componentId);
                                            console.log(`🔵 Autocomplete: Found Livewire component ${componentId}`);
                                        } catch (e) {
                                            console.warn('🔵 Autocomplete: Could not find component:', e);
                                        }
                                    }
                                    break;
                                }
                                element = element.parentElement;
                            }
                            
                            // Update Livewire property directly
                            if (component) {
                                try {
                                    // Property name is the fieldType (pol or pod)
                                    component.set(fieldType, selectedValue);
                                    console.log(`✅ Autocomplete: Updated Livewire ${fieldType} = "${selectedValue}"`);
                                } catch (e) {
                                    console.error('🔴 Autocomplete: Error updating Livewire:', e);
                                }
                            } else {
                                console.warn('⚠️ Autocomplete: Could not find Livewire component to update directly');
                            }
                        });
                    });
                } else {
                    // No matches and empty query - hide dropdown
                    dropdown.classList.add('hidden');
                    console.log(`🔵 Autocomplete: ${fieldType} - Dropdown hidden (no matches and no query)`);
                }
            }
            
            // Show dropdown on focus/click
            input.addEventListener('focus', function() {
                console.log(`🔵 Autocomplete: ${input.id} focus event`);
                renderDropdown(this.value);
            });
            
            input.addEventListener('click', function() {
                console.log(`🔵 Autocomplete: ${input.id} click event`);
                renderDropdown(this.value);
            });
            
            // Update dropdown as user types
            input.addEventListener('input', function() {
                renderDropdown(this.value);
            });
            
            // Hide dropdown when clicking outside
            // Use a more specific handler that checks if click is outside both input and dropdown
            document.addEventListener('click', function(e) {
                const clickedOnInput = input.contains(e.target);
                const clickedOnDropdown = dropdown.contains(e.target);
                
                if (!clickedOnInput && !clickedOnDropdown) {
                    dropdown.classList.add('hidden');
                    console.log(`🔵 Autocomplete: ${input.id} - Dropdown hidden (clicked outside)`);
                }
            }, true); // Use capture phase to catch events early
            
            // Allow pressing Enter to use custom value
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && this.value.trim()) {
                    dropdown.classList.add('hidden');
                    e.preventDefault();
                }
            });
        }
        
        // Setup both inputs
        setupAutocomplete(polInput);
        setupAutocomplete(podInput);
        
        // IMPORTANT: Re-initialize autocomplete after Livewire updates
        // Livewire re-renders can remove our JavaScript-created dropdowns
        // Use a small delay to ensure DOM is ready after Livewire update
        let livewireUpdateTimeout = null;
        document.addEventListener('livewire:update', function() {
            console.log('🔵 Autocomplete: Livewire update detected');
            
            // Clear any pending timeout
            if (livewireUpdateTimeout) {
                clearTimeout(livewireUpdateTimeout);
            }
            
            // Wait a moment for Livewire to finish updating DOM
            livewireUpdateTimeout = setTimeout(function() {
                console.log('🔵 Autocomplete: Checking for missing dropdowns after Livewire update');
                
                // Re-find inputs (they might be new elements after Livewire re-render)
                const polInputNew = document.getElementById('pol');
                const podInputNew = document.getElementById('pod');
                
                if (polInputNew && podInputNew) {
                    // Check if autocomplete is already set up
                    const polHasDropdown = polInputNew.parentElement?.querySelector('.autocomplete-dropdown');
                    const podHasDropdown = podInputNew.parentElement?.querySelector('.autocomplete-dropdown');
                    const polNeedsSetup = !polHasDropdown && !polInputNew.dataset.autocompleteSetup;
                    const podNeedsSetup = !podHasDropdown && !podInputNew.dataset.autocompleteSetup;
                    
                    console.log(`🔵 Autocomplete: After Livewire update - POL: dropdown=${!!polHasDropdown}, flag=${!!polInputNew.dataset.autocompleteSetup}, needs=${polNeedsSetup}`);
                    console.log(`🔵 Autocomplete: After Livewire update - POD: dropdown=${!!podHasDropdown}, flag=${!!podInputNew.dataset.autocompleteSetup}, needs=${podNeedsSetup}`);
                    
                    if (polNeedsSetup) {
                        console.log('🔵 Autocomplete: Re-initializing POL autocomplete');
                        // Clear flag in case it was set on old element
                        delete polInputNew.dataset.autocompleteSetup;
                        setupAutocomplete(polInputNew);
                    }
                    
                    if (podNeedsSetup) {
                        console.log('🔵 Autocomplete: Re-initializing POD autocomplete');
                        // Clear flag in case it was set on old element
                        delete podInputNew.dataset.autocompleteSetup;
                        setupAutocomplete(podInputNew);
                    }
                } else {
                    console.warn(`🔴 Autocomplete: After Livewire update - POL=${!!polInputNew}, POD=${!!podInputNew}`);
                }
            }, 50); // Small delay to ensure DOM is ready
        });
    }
});
</script>
