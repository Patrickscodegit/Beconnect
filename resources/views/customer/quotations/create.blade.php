@extends('customer.layout')

@section('title', 'Request Quotation')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
    
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
            Request a Quotation
        </h1>
        <p class="text-gray-600">
            Fill out the details below and we'll provide you with a competitive quote within 24 hours.
        </p>
    </div>

    <!-- Form -->
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <form action="{{ route('customer.quotations.store') }}" method="POST" enctype="multipart/form-data" 
                  x-data="quotationForm()" @submit="validateForm">
                @csrf
                
                <!-- Contact Information (Pre-filled for Customer) -->
                <div class="p-8 bg-gradient-to-r from-blue-600 to-blue-700">
                    <h2 class="text-2xl font-bold text-white mb-6">
                        <i class="fas fa-user mr-2"></i>Contact Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="contact_phone" class="block text-sm font-medium text-white mb-2">
                                Phone Number <span class="text-red-300">*</span>
                            </label>
                            <input type="tel" id="contact_phone" name="contact_phone" required
                                   value="{{ old('contact_phone', $prefill['contact_phone'] ?? '') }}"
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
                                   value="{{ old('contact_company', $prefill['contact_company'] ?? '') }}"
                                   class="form-input w-full px-4 py-3 rounded-lg border-0 bg-white bg-opacity-90 focus:bg-opacity-100"
                                   placeholder="Your Company Ltd">
                            @error('contact_company')
                                <p class="text-red-300 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div class="col-span-2 bg-blue-800 bg-opacity-30 rounded-lg p-4">
                            <p class="text-white text-sm">
                                <i class="fas fa-info-circle mr-2"></i>
                                Logged in as: <strong>{{ $user->name }}</strong> ({{ $user->email }})
                            </p>
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
                            <select id="pol" name="pol" required
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select POL</option>
                                @foreach($polPorts as $port)
                                    <option value="{{ $port->name }}" 
                                            {{ old('pol', $prefill['pol'] ?? '') == $port->name ? 'selected' : '' }}>
                                        {{ $port->name }} ({{ $port->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('pol')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="pod" class="block text-sm font-medium text-gray-700 mb-2">
                                Port of Discharge (POD) <span class="text-red-500">*</span>
                            </label>
                            <select id="pod" name="pod" required
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select POD</option>
                                @foreach($podPorts as $port)
                                    <option value="{{ $port->name }}" 
                                            {{ old('pod', $prefill['pod'] ?? '') == $port->name ? 'selected' : '' }}>
                                        {{ $port->name }} ({{ $port->code }})
                                    </option>
                                @endforeach
                            </select>
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
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
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
                        
                        <div>
                            <label for="trade_direction" class="block text-sm font-medium text-gray-700 mb-2">
                                Trade Direction <span class="text-red-500">*</span>
                            </label>
                            <select id="trade_direction" name="trade_direction" required
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select Direction</option>
                                <option value="export" {{ old('trade_direction') == 'export' ? 'selected' : '' }}>Export</option>
                                <option value="import" {{ old('trade_direction') == 'import' ? 'selected' : '' }}>Import</option>
                            </select>
                            @error('trade_direction')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="customer_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Customer Type <span class="text-red-500">*</span>
                            </label>
                            <select id="customer_type" name="customer_type" required
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select Type</option>
                                @foreach($customerTypes as $key => $label)
                                    <option value="{{ $key }}" {{ old('customer_type') == $key ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('customer_type')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="customer_role" class="block text-sm font-medium text-gray-700 mb-2">
                                Your Role <span class="text-red-500">*</span>
                            </label>
                            <select id="customer_role" name="customer_role" required
                                    class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="">Select Role</option>
                                @foreach($customerRoles as $key => $label)
                                    <option value="{{ $key }}" {{ old('customer_role') == $key ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('customer_role')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Cargo Information -->
                <div class="p-8 border-b">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">
                        <i class="fas fa-box mr-2"></i>Cargo Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="cargo_description" class="block text-sm font-medium text-gray-700 mb-2">
                                Cargo Description <span class="text-red-500">*</span>
                            </label>
                            <textarea id="cargo_description" name="cargo_description" required rows="3"
                                      class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                                      placeholder="Describe your cargo in detail...">{{ old('cargo_description') }}</textarea>
                            @error('cargo_description')
                                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        
                        <div>
                            <label for="commodity_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Commodity Type <span class="text-red-500">*</span>
                            </label>
                            <select id="commodity_type" name="commodity_type" required
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

                <!-- Submit -->
                <div class="p-8 bg-gray-50">
                    <div class="flex justify-between items-center">
                        <a href="{{ route('customer.quotations.index') }}" 
                           class="text-gray-600 hover:text-gray-900 font-medium">
                            <i class="fas fa-arrow-left mr-2"></i>Cancel
                        </a>
                        <button type="submit" 
                                class="inline-flex items-center px-8 py-4 bg-blue-600 text-white rounded-lg font-semibold text-lg shadow-lg hover:bg-blue-700 hover:shadow-xl transition-all duration-300">
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
        },
        
        validateForm(event) {
            // Client-side validation can be added here
            return true;
        }
    }
}
</script>
@endsection
