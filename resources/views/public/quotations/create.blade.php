@extends('public.quotations.layout')

@section('title', 'Request Quotation - Belgaco Logistics')
@section('description', 'Get a competitive quote for your shipping needs. Professional logistics services worldwide.')

@section('content')
<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">
                Request a Quotation
            </h1>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">
                Get a competitive quote for your shipping needs. Our experts will provide you with the best rates and service options.
            </p>
            
            @if(isset($intake) && $intake)
                <div class="mt-6 bg-blue-50 border-l-4 border-blue-500 p-4 rounded max-w-2xl mx-auto">
                    <div class="flex items-center">
                        <i class="fas fa-magic text-blue-500 mr-3 text-xl"></i>
                        <div>
                            <h3 class="text-sm font-semibold text-blue-800">Auto-Populated from Intake #{{ $intake->id }}</h3>
                            <p class="text-sm text-blue-600 mt-1">
                                We've automatically filled in {{ count($commodityItems ?? []) }} commodity item(s) from your intake. 
                                Please review and edit as needed.
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Form -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden">
            <form action="{{ route('public.quotations.store') }}" method="POST" enctype="multipart/form-data" 
                  x-data="{ quotationMode: 'detailed', ...quotationForm() }"
                  @submit="syncCommodityItems($event)">
                @csrf
                
                <!-- Contact Information -->
                <div class="form-section p-8">
                    <h2 class="text-2xl font-bold text-white mb-6">
                        <i class="fas fa-user mr-2"></i>Contact Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="contact_name" class="block text-sm font-medium text-white mb-2">
                                Full Name <span class="text-red-300">*</span>
                            </label>
                            <input type="text" id="contact_name" name="contact_name" required
                                   value="{{ old('contact_name') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border-0 bg-white bg-opacity-90 focus:bg-opacity-100"
                                   placeholder="Enter your full name">
                            @error('contact_name')
                                <p class="text-red-300 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="contact_email" class="block text-sm font-medium text-white mb-2">
                                Email Address <span class="text-red-300">*</span>
                            </label>
                            <input type="email" id="contact_email" name="contact_email" required
                                   value="{{ old('contact_email') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border-0 bg-white bg-opacity-90 focus:bg-opacity-100"
                                   placeholder="your@email.com">
                            @error('contact_email')
                                <p class="text-red-300 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="contact_phone" class="block text-sm font-medium text-white mb-2">
                                Phone Number <span class="text-red-300">*</span>
                            </label>
                            <input type="tel" id="contact_phone" name="contact_phone" required
                                   value="{{ old('contact_phone') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border-0 bg-white bg-opacity-90 focus:bg-opacity-100"
                                   placeholder="+32 123 45 67 89">
                            @error('contact_phone')
                                <p class="text-red-300 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="contact_company" class="block text-sm font-medium text-white mb-2">
                                Company Name
                            </label>
                            <input type="text" id="contact_company" name="contact_company"
                                   value="{{ old('contact_company') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border-0 bg-white bg-opacity-90 focus:bg-opacity-100"
                                   placeholder="Your Company Ltd">
                            @error('contact_company')
                                <p class="text-red-300 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Route Information -->
                <div class="p-8 border-b">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">
                        <i class="fas fa-route mr-2"></i>Route Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div>
                            <label for="por" class="block text-sm font-medium text-gray-700 mb-2">
                                Place of Receipt (POR)
                            </label>
                            <input type="text" id="por" name="por"
                                   value="{{ old('por', $prefill['por'] ?? '') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="e.g., Brussels">
                            @error('por')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="pol" class="block text-sm font-medium text-gray-700 mb-2">
                                Port of Loading (POL) <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   id="pol" 
                                   name="pol" 
                                   required
                                   value="{{ old('pol', $prefill['pol'] ?? '') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="Search or type any port/airport...">
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-info-circle"></i> Type to search, or enter a custom port/airport name
                            </p>
                            @error('pol')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="pod" class="block text-sm font-medium text-gray-700 mb-2">
                                Port of Discharge (POD) <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   id="pod" 
                                   name="pod" 
                                   required
                                   value="{{ old('pod', $prefill['pod'] ?? '') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="Search or type any port/airport...">
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-info-circle"></i> Type to search, or enter a custom port/airport name
                            </p>
                            @error('pod')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="fdest" class="block text-sm font-medium text-gray-700 mb-2">
                                Final Destination (FDEST)
                            </label>
                            <input type="text" id="fdest" name="fdest"
                                   value="{{ old('fdest', $prefill['fdest'] ?? '') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="e.g., Lagos">
                            @error('fdest')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Service Information -->
                <div class="p-8 border-b">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">
                        <i class="fas fa-ship mr-2"></i>Service Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="preferred_departure_date" class="block text-sm font-medium text-gray-700 mb-2">
                                Preferred Departure Date
                            </label>
                            <input type="date" id="preferred_departure_date" name="preferred_departure_date"
                                   value="{{ old('preferred_departure_date') }}"
                                   min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                            @error('preferred_departure_date')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="service_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Service Type <span class="text-red-500">*</span>
                            </label>
                            <select id="service_type" name="service_type" required
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select Service</option>
                                @foreach($serviceTypes as $key => $service)
                                    <option value="{{ $key }}" 
                                            {{ old('service_type', $prefill['service_type'] ?? '') == $key ? 'selected' : '' }}>
                                        {{ is_array($service) ? $service['name'] : $service }}
                                    </option>
                                @endforeach
                            </select>
                            @error('service_type')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Select Sailing (Optional) -->
                <div class="p-8 border-b" x-data="scheduleSelector()">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">
                        <i class="fas fa-calendar-alt mr-2"></i>Select Sailing <span class="text-sm font-normal text-gray-500">(Optional)</span>
                    </h2>
                    <p class="text-gray-600 mb-6">
                        Choose a specific sailing if you have a preferred departure date and carrier. This helps us provide more accurate pricing.
                    </p>
                    
                    <div class="space-y-4">
                        <!-- Schedule Dropdown -->
                        <div>
                            <label for="selected_schedule_id" class="block text-sm font-medium text-gray-700 mb-2">
                                Available Sailings
                            </label>
                            <select 
                                id="selected_schedule_id" 
                                name="selected_schedule_id"
                                x-model="selectedSchedule"
                                :disabled="loading || schedules.length === 0"
                                class="form-select w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 disabled:bg-gray-100 disabled:cursor-not-allowed"
                            >
                                <option value="">
                                    <span x-show="!loading && !polSelected && !podSelected">Please select POL and POD first</span>
                                    <span x-show="!loading && (polSelected || podSelected) && schedules.length === 0">No sailings available for this route</span>
                                    <span x-show="!loading && schedules.length > 0">-- Select a sailing (optional) --</span>
                                    <span x-show="loading">Loading schedules...</span>
                                </option>
                                <template x-for="schedule in schedules" :key="schedule.id">
                                    <option :value="schedule.id" x-text="schedule.label"></option>
                                </template>
                            </select>
                            <p class="text-gray-500 text-sm mt-2" x-show="loading">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Searching for available sailings...
                            </p>
                            <p class="text-gray-500 text-sm mt-2" x-show="!loading && schedules.length === 0 && (polSelected && podSelected)">
                                <i class="fas fa-info-circle mr-1"></i> No scheduled sailings found for this route. You can still submit your request.
                            </p>
                        </div>

                        <!-- Selected Schedule Details -->
                        <div x-show="selectedScheduleDetails" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h3 class="font-semibold text-gray-900 mb-2">
                                <i class="fas fa-info-circle text-blue-600 mr-1"></i> Selected Sailing Details
                            </h3>
                            <div class="text-sm space-y-1" x-html="selectedScheduleDetails"></div>
                        </div>
                    </div>
                </div>

                <!-- Cargo Information -->
                <div class="p-8 border-b">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">
                        <i class="fas fa-box mr-2"></i>Cargo Information
                    </h2>
                    
                    <!-- Toggle Between Quick Quote and Detailed Quote -->
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-lg border-2 border-blue-200 mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">
                            <i class="fas fa-exchange-alt mr-2 text-blue-600"></i>Choose Your Input Method
                        </h3>
                        
                        <div class="space-y-3">
                            <!-- Quick Quote Option -->
                            <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer transition-all"
                                   :class="quotationMode === 'quick' ? 'border-blue-500 bg-blue-50' : 'border-gray-300 bg-white hover:border-blue-300'">
                                <input type="radio" x-model="quotationMode" value="quick" class="mt-1 mr-3 w-5 h-5 text-blue-600">
                                <div class="flex-1">
                                    <div class="font-semibold text-gray-900">
                                        <i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Quote
                                    </div>
                                    <p class="text-sm text-gray-600 mt-1">Fast entry for simple shipments with basic cargo details</p>
                                </div>
                            </label>
                            
                            <!-- Detailed Quote Option -->
                            <label class="flex items-start p-4 border-2 rounded-lg cursor-pointer transition-all"
                                   :class="quotationMode === 'detailed' ? 'border-green-500 bg-green-50' : 'border-gray-300 bg-white hover:border-green-300'">
                                <input type="radio" x-model="quotationMode" value="detailed" class="mt-1 mr-3 w-5 h-5 text-green-600">
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
                    
                    <!-- Hidden field to track mode -->
                    <input type="hidden" name="quotation_mode" :value="quotationMode">
                    
                    <!-- Quick Quote Form -->
                    <div x-show="quotationMode === 'quick'" x-cloak>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="cargo_description" class="block text-sm font-medium text-gray-700 mb-2">
                                Cargo Description
                            </label>
                            <textarea id="cargo_description" name="cargo_description" rows="3"
                                      class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                      placeholder="Describe your cargo in detail...">{{ old('cargo_description') }}</textarea>
                            @error('cargo_description')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="commodity_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Commodity Type
                            </label>
                            <select id="commodity_type" name="commodity_type"
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select Type</option>
                                <option value="cars" {{ old('commodity_type') == 'cars' ? 'selected' : '' }}>Cars/Vehicles</option>
                                <option value="machinery" {{ old('commodity_type') == 'machinery' ? 'selected' : '' }}>Machinery</option>
                                <option value="general_cargo" {{ old('commodity_type') == 'general_cargo' ? 'selected' : '' }}>General Cargo</option>
                                <option value="personal_effects" {{ old('commodity_type') == 'personal_effects' ? 'selected' : '' }}>Personal Effects</option>
                                <option value="other" {{ old('commodity_type') == 'other' ? 'selected' : '' }}>Other</option>
                            </select>
                            @error('commodity_type')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="cargo_weight" class="block text-sm font-medium text-gray-700 mb-2">
                                Weight (kg)
                            </label>
                            <input type="number" id="cargo_weight" name="cargo_weight" step="0.01" min="0"
                                   value="{{ old('cargo_weight') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="e.g., 1500">
                            @error('cargo_weight')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="cargo_volume" class="block text-sm font-medium text-gray-700 mb-2">
                                Volume (m³)
                            </label>
                            <input type="number" id="cargo_volume" name="cargo_volume" step="0.01" min="0"
                                   value="{{ old('cargo_volume') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="e.g., 25.5">
                            @error('cargo_volume')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="cargo_dimensions" class="block text-sm font-medium text-gray-700 mb-2">
                                Dimensions
                            </label>
                            <input type="text" id="cargo_dimensions" name="cargo_dimensions"
                                   value="{{ old('cargo_dimensions') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                   placeholder="L x W x H (cm)">
                            @error('cargo_dimensions')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <label for="special_requirements" class="block text-sm font-medium text-gray-700 mb-2">
                            Special Requirements
                        </label>
                        <textarea id="special_requirements" name="special_requirements" rows="3"
                                  class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                  placeholder="Any special handling, documentation, or requirements...">{{ old('special_requirements') }}</textarea>
                        @error('special_requirements')
                            <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    </div>
                    <!-- End Quick Quote Form -->
                </div>

                <!-- Detailed Quote Form (Multi-Commodity Items) -->
                <div x-show="quotationMode === 'detailed'" x-cloak class="p-8 border-b">
                    @livewire('commodity-items-repeater', [
                        'existingItems' => old('commodity_items') ? json_decode(old('commodity_items'), true) : ($commodityItems ?? []),
                        'serviceType' => old('service_type', $prefill['service_type'] ?? ''),
                        'unitSystem' => old('unit_system', 'metric')
                    ])
                </div>

                <!-- File Uploads -->
                <div class="p-8 border-b">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">
                        <i class="fas fa-paperclip mr-2"></i>Supporting Documents
                    </h2>
                    
                    <div class="border-2 border-dashed rounded-lg p-8 text-center transition-all duration-300"
                         :class="isDragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300 hover:border-blue-400'"
                         @dragover.prevent="isDragging = true"
                         @dragleave.prevent="isDragging = false"
                         @drop.prevent="handleDrop($event)"
                         @click="$refs.fileInput.click()">
                        
                        <input type="file" 
                               x-ref="fileInput"
                               id="supporting_files" 
                               name="supporting_files[]" 
                               multiple
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt"
                               @change="handleFileUpload($event)"
                               class="hidden">
                        
                        <div class="cursor-pointer">
                            <i class="fas fa-cloud-upload-alt text-5xl mb-4 transition-colors"
                               :class="isDragging ? 'text-blue-500' : 'text-gray-400'"></i>
                            <p class="text-lg font-medium mb-2"
                               :class="isDragging ? 'text-blue-600' : 'text-gray-600'">
                                <span x-show="!isDragging">Click to upload or drag and drop</span>
                                <span x-show="isDragging" class="text-blue-600 font-bold">Drop files here</span>
                            </p>
                            <p class="text-sm text-gray-500">
                                PDF, Images, Office documents (max 10MB each, up to 5 files)
                            </p>
                        </div>
                        
                        @error('supporting_files')
                            <p class="text-red-600 text-sm mt-2">{{ $message }}</p>
                        @enderror
                        @error('supporting_files.*')
                            <p class="text-red-600 text-sm mt-2">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <!-- File List -->
                    <div x-show="fileList.length > 0" 
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 transform scale-95"
                         x-transition:enter-end="opacity-100 transform scale-100"
                         class="mt-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3">
                            Selected Files (<span x-text="fileList.length"></span>/5)
                        </h4>
                        <ul class="space-y-2">
                            <template x-for="(file, index) in fileList" :key="index">
                                <li class="flex items-center justify-between bg-gray-50 p-4 rounded-lg border border-gray-200 hover:bg-gray-100 transition-colors">
                                    <div class="flex items-center flex-1 min-w-0">
                                        <i class="fas text-lg mr-3"
                                           :class="{
                                               'fa-file-pdf text-red-500': file.name.endsWith('.pdf'),
                                               'fa-file-image text-blue-500': /\.(jpg|jpeg|png)$/i.test(file.name),
                                               'fa-file-word text-blue-600': /\.(doc|docx)$/i.test(file.name),
                                               'fa-file-excel text-green-600': /\.(xls|xlsx)$/i.test(file.name),
                                               'fa-file text-gray-500': !/\.(pdf|jpg|jpeg|png|doc|docx|xls|xlsx)$/i.test(file.name)
                                           }"></i>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 truncate" x-text="file.name"></p>
                                            <p class="text-xs text-gray-500" x-text="formatFileSize(file.size)"></p>
                                        </div>
                                    </div>
                                    <button type="button" 
                                            @click="removeFile(index)" 
                                            class="ml-4 text-red-500 hover:text-red-700 hover:bg-red-50 p-2 rounded transition-colors"
                                            title="Remove file">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                <!-- Terms and Submit -->
                <div class="p-8">
                    <div class="mb-6">
                        <label class="flex items-start">
                            <input type="checkbox" name="terms_accepted" required
                                   class="mt-1 mr-3 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700">
                                I accept the <a href="#" class="text-blue-600 hover:underline">Terms and Conditions</a> 
                                and agree to be contacted regarding this quotation request.
                            </span>
                        </label>
                        @error('terms_accepted')
                            <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div class="mb-6">
                        <label class="flex items-start">
                            <input type="checkbox" name="privacy_policy_accepted" required
                                   class="mt-1 mr-3 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-sm text-gray-700">
                                I accept the <a href="#" class="text-blue-600 hover:underline">Privacy Policy</a> 
                                and consent to the processing of my personal data.
                            </span>
                        </label>
                        @error('privacy_policy_accepted')
                            <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div class="flex justify-center">
                        <button type="submit" 
                                class="btn-primary text-white px-8 py-4 rounded-lg font-semibold text-lg shadow-lg hover:shadow-xl transition-all duration-300">
                            <i class="fas fa-paper-plane mr-2"></i>
                            Submit Quotation Request
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function quotationForm() {
    return {
        isDragging: false,
        fileList: [],
        
        init() {
            // Prevent default drag behaviors on the entire window
            window.addEventListener('dragover', (e) => e.preventDefault());
            window.addEventListener('drop', (e) => e.preventDefault());
        },
        
        handleFileUpload(event) {
            const input = event.target;
            this.displayFiles(input.files);
        },
        
        handleDrop(event) {
            this.isDragging = false;
            const files = event.dataTransfer.files;
            
            if (files.length > 0) {
                // We need to manually update the file input with dropped files
                const input = this.$refs.fileInput;
                
                // Create a new DataTransfer and add files
                const dataTransfer = new DataTransfer();
                
                // Add dropped files
                Array.from(files).forEach(file => {
                    // Validate file size
                    if (file.size > 10 * 1024 * 1024) {
                        alert(`File "${file.name}" is too large. Maximum size is 10MB.`);
                        return;
                    }
                    
                    // Validate file type
                    const allowedExtensions = ['.pdf', '.jpg', '.jpeg', '.png', '.doc', '.docx', '.xls', '.xlsx', '.txt'];
                    const hasValidExtension = allowedExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
                    
                    if (!hasValidExtension) {
                        alert(`File "${file.name}" has an invalid type. Only PDF, images, and office documents are allowed.`);
                        return;
                    }
                    
                    dataTransfer.items.add(file);
                });
                
                // Check file count limit
                if (dataTransfer.files.length > 5) {
                    alert('Maximum 5 files allowed');
                    return;
                }
                
                // Update the input with the new files
                input.files = dataTransfer.files;
                
                // Display the files
                this.displayFiles(input.files);
            }
        },
        
        displayFiles(files) {
            this.fileList = Array.from(files).map(file => ({
                name: file.name,
                size: file.size,
                type: file.type
            }));
        },
        
        removeFile(index) {
            const input = this.$refs.fileInput;
            const dataTransfer = new DataTransfer();
            
            // Add all files except the one at the specified index
            Array.from(input.files).forEach((file, i) => {
                if (i !== index) {
                    dataTransfer.items.add(file);
                }
            });
            
            // Update the input
            input.files = dataTransfer.files;
            
            // Update display
            this.displayFiles(input.files);
        },
        
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    }
}

// Sync Livewire commodity items to hidden input before form submission
function syncCommodityItems(event) {
    try {
        // Find the Livewire component
        const livewireComponent = document.querySelector('[wire\\:id]');
        
        if (livewireComponent && typeof Livewire !== 'undefined') {
            // Get the component ID
            const componentId = livewireComponent.getAttribute('wire:id');
            const component = Livewire.find(componentId);
            
            if (component) {
                // Get items from Livewire component
                const items = component.get('items');
                
                // Update hidden input with current items
                const hiddenInput = document.querySelector('input[name="commodity_items"]');
                if (hiddenInput) {
                    hiddenInput.value = JSON.stringify(items);
                    console.log('✅ Synced commodity items before submission:', items.length, 'items');
                }
            }
        }
    } catch (error) {
        console.error('Error syncing commodity items:', error);
    }
    
    // Let form submit normally
    return true;
}

// Initialize searchable port/airport selects with custom input
document.addEventListener('DOMContentLoaded', function() {
    // Port and airport data from backend
    const seaports = @json($polPortsFormatted->merge($podPortsFormatted)->unique());
    const airports = @json($airportsFormatted);
    const serviceTypeSelect = document.getElementById('service_type');
    const polInput = document.getElementById('pol');
    const podInput = document.getElementById('pod');
    
    // Initialize autocomplete for POL and POD
    if (polInput && podInput && serviceTypeSelect) {
        // Function to get current port list based on service type
        function getCurrentPortList() {
            const serviceType = serviceTypeSelect.value;
            const isAirfreight = serviceType === 'AIRFREIGHT_EXPORT' || serviceType === 'AIRFREIGHT_IMPORT';
            return isAirfreight ? airports : seaports;
        }
        
        // Setup autocomplete for an input field
        function setupAutocomplete(input) {
            const wrapper = document.createElement('div');
            wrapper.style.position = 'relative';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            
            const dropdown = document.createElement('div');
            dropdown.className = 'absolute z-50 w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden';
            wrapper.appendChild(dropdown);
            
            input.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                const portList = getCurrentPortList();
                
                if (query.length === 0) {
                    dropdown.classList.add('hidden');
                    return;
                }
                
                // Filter matching ports
                const matches = Object.entries(portList).filter(([key, value]) => 
                    key.toLowerCase().includes(query) || value.toLowerCase().includes(query)
                ).slice(0, 10);
                
                if (matches.length === 0) {
                    dropdown.innerHTML = `
                        <div class="px-4 py-3 text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-2"></i>
                            No matches found. Press Enter to use "${this.value}" as a custom port.
                        </div>
                    `;
                    dropdown.classList.remove('hidden');
                } else {
                    dropdown.innerHTML = matches.map(([key, value]) => `
                        <div class="px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0" 
                             data-value="${key}">
                            <div class="font-medium text-gray-900">${key}</div>
                            <div class="text-sm text-gray-500">${value}</div>
                        </div>
                    `).join('');
                    dropdown.classList.remove('hidden');
                    
                    // Add click handlers
                    dropdown.querySelectorAll('[data-value]').forEach(item => {
                        item.addEventListener('click', function() {
                            input.value = this.dataset.value;
                            dropdown.classList.add('hidden');
                            input.dispatchEvent(new Event('change'));
                        });
                    });
                }
            });
            
            // Hide dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
            
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
        
        // Update placeholder text when service type changes
        serviceTypeSelect.addEventListener('change', function() {
            const isAirfreight = this.value === 'AIRFREIGHT_EXPORT' || this.value === 'AIRFREIGHT_IMPORT';
            const placeholder = isAirfreight ? 'Search or type any airport...' : 'Search or type any port...';
            polInput.placeholder = placeholder;
            podInput.placeholder = placeholder;
        });
    }
});

// Alpine.js component for schedule selection
function scheduleSelector() {
    return {
        schedules: [],
        selectedSchedule: '{{ old('selected_schedule_id') }}',
        loading: false,
        polSelected: false,
        podSelected: false,
        
        init() {
            // Watch for POL/POD changes
            const polSelect = document.getElementById('pol');
            const podSelect = document.getElementById('pod');
            
            if (polSelect) {
                polSelect.addEventListener('change', () => {
                    this.polSelected = polSelect.value !== '';
                    this.fetchSchedules();
                });
                // Also check on input for text field
                polSelect.addEventListener('input', () => {
                    this.polSelected = polSelect.value !== '';
                });
                this.polSelected = polSelect.value !== '';
            }
            
            if (podSelect) {
                podSelect.addEventListener('change', () => {
                    this.podSelected = podSelect.value !== '';
                    this.fetchSchedules();
                });
                // Also check on input for text field
                podSelect.addEventListener('input', () => {
                    this.podSelected = podSelect.value !== '';
                });
                this.podSelected = podSelect.value !== '';
            }
            
            // Initial fetch if both are selected
            if (this.polSelected && this.podSelected) {
                this.fetchSchedules();
            }
        },
        
        async fetchSchedules() {
            const polSelect = document.getElementById('pol');
            const podSelect = document.getElementById('pod');
            const serviceTypeSelect = document.getElementById('service_type');
            
            const pol = polSelect?.value;
            const pod = podSelect?.value;
            const serviceType = serviceTypeSelect?.value;
            
            // Reset selection when POL/POD changes
            this.selectedSchedule = '';
            
            if (!pol || !pod) {
                this.schedules = [];
                return;
            }
            
            this.loading = true;
            
            try {
                const url = new URL('{{ route('api.schedules.search') }}', window.location.origin);
                url.searchParams.append('pol', pol);
                url.searchParams.append('pod', pod);
                if (serviceType) {
                    url.searchParams.append('service_type', serviceType);
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    this.schedules = data.schedules || [];
                } else {
                    this.schedules = [];
                    console.warn('Schedule search failed:', data.message);
                }
            } catch (error) {
                console.error('Error fetching schedules:', error);
                this.schedules = [];
            } finally {
                this.loading = false;
            }
        },
        
        get selectedScheduleDetails() {
            if (!this.selectedSchedule) return null;
            
            const schedule = this.schedules.find(s => s.id == this.selectedSchedule);
            if (!schedule) return null;
            
            return `
                <div><strong>Carrier:</strong> ${schedule.carrier}</div>
                <div><strong>Route:</strong> ${schedule.pol} → ${schedule.pod}</div>
                <div><strong>Service:</strong> ${schedule.service_name}</div>
                <div><strong>Departure:</strong> ${schedule.departure_date || 'TBA'}</div>
                <div><strong>Transit Time:</strong> ${schedule.transit_days} days</div>
                <div><strong>Frequency:</strong> ${schedule.frequency}</div>
            `;
        }
    }
}
</script>
@endsection
