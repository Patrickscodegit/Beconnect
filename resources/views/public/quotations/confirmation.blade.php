@extends('public.quotations.layout')

@section('title', 'Quotation Request Confirmed - Belgaco Logistics')

@section('content')
<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Success Message -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400 text-3xl"></i>
                </div>
                <div class="ml-4">
                    <h2 class="text-2xl font-bold text-green-800 mb-2">
                        Quotation Request Submitted Successfully!
                    </h2>
                    <p class="text-green-700">
                        Thank you for your request. We have received your quotation request and will contact you within 24 hours.
                    </p>
                </div>
            </div>
        </div>

        <!-- Request Details -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6">
                <h3 class="text-2xl font-bold text-white">
                    <i class="fas fa-file-alt mr-2"></i>Request Details
                </h3>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Request Information</h4>
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Request Number</dt>
                                <dd class="text-lg font-semibold text-blue-600">{{ $quotationRequest->request_number }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Status</dt>
                                <dd class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                                    <i class="fas fa-clock mr-1"></i>
                                    Pending Review
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Submitted</dt>
                                <dd class="text-gray-900">{{ $quotationRequest->created_at->format('F j, Y \a\t g:i A') }}</dd>
                            </div>
                        </dl>
                    </div>
                    
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Contact Information</h4>
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Name</dt>
                                <dd class="text-gray-900">{{ $quotationRequest->contact_name }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Email</dt>
                                <dd class="text-gray-900">{{ $quotationRequest->contact_email }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Phone</dt>
                                <dd class="text-gray-900">{{ $quotationRequest->contact_phone }}</dd>
                            </div>
                            @if($quotationRequest->contact_company || $quotationRequest->client_name)
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Company</dt>
                                <dd class="text-gray-900">{{ $quotationRequest->client_name ?: $quotationRequest->contact_company }}</dd>
                            </div>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Intro -->
        @if($quotationRequest->renderIntroText())
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-indigo-600 to-blue-600 p-6">
                <h3 class="text-2xl font-bold text-white">
                    <i class="fas fa-file-signature mr-2"></i>Intro
                </h3>
            </div>
            <div class="p-6">
                <p class="text-gray-900 whitespace-pre-line">{{ $quotationRequest->renderIntroText() }}</p>
            </div>
        </div>
        @endif

        <!-- Route Information -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-green-600 to-blue-600 p-6">
                <h3 class="text-2xl font-bold text-white">
                    <i class="fas fa-route mr-2"></i>Route Information
                </h3>
            </div>
            
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-4">
                        @if($quotationRequest->por)
                            <div class="text-center">
                                <div class="bg-gray-100 rounded-full w-16 h-16 flex items-center justify-center mb-2">
                                    <i class="fas fa-map-marker-alt text-gray-600"></i>
                                </div>
                                <p class="text-sm font-medium text-gray-900">{{ $quotationRequest->por }}</p>
                                <p class="text-xs text-gray-500">POR</p>
                            </div>
                            <div class="flex-1 h-1 bg-gray-300 rounded"></div>
                        @endif
                        
                        <div class="text-center">
                            <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mb-2">
                                <i class="fas fa-ship text-blue-600"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-900">{{ $quotationRequest->pol }}</p>
                            <p class="text-xs text-gray-500">POL</p>
                        </div>
                        
                        <div class="flex-1 h-1 bg-gray-300 rounded"></div>
                        
                        <div class="text-center">
                            <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mb-2">
                                <i class="fas fa-anchor text-green-600"></i>
                            </div>
                            <p class="text-sm font-medium text-gray-900">{{ $quotationRequest->pod }}</p>
                            <p class="text-xs text-gray-500">POD</p>
                        </div>
                        
                        @if($quotationRequest->fdest)
                            <div class="flex-1 h-1 bg-gray-300 rounded"></div>
                            <div class="text-center">
                                <div class="bg-gray-100 rounded-full w-16 h-16 flex items-center justify-center mb-2">
                                    <i class="fas fa-flag text-gray-600"></i>
                                </div>
                                <p class="text-sm font-medium text-gray-900">{{ $quotationRequest->fdest }}</p>
                                <p class="text-xs text-gray-500">FDEST</p>
                            </div>
                        @endif
                        
                        @if($quotationRequest->in_transit_to)
                            <div class="flex-1 h-1 bg-gray-300 rounded"></div>
                            <div class="text-center">
                                <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mb-2">
                                    <i class="fas fa-exchange-alt text-purple-600"></i>
                                </div>
                                <p class="text-sm font-medium text-gray-900">{{ $quotationRequest->in_transit_to }}</p>
                                <p class="text-xs text-gray-500">In Transit To</p>
                            </div>
                        @endif
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Service Type</dt>
                        <dd class="text-gray-900">
                            @php
                                $serviceType = config('quotation.service_types.' . $quotationRequest->service_type);
                                $serviceName = is_array($serviceType) ? $serviceType['name'] : ($serviceType ?: $quotationRequest->service_type);
                            @endphp
                            {{ $serviceName }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Trade Direction</dt>
                        <dd class="text-gray-900 capitalize">{{ $quotationRequest->trade_direction }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Customer Type</dt>
                        <dd class="text-gray-900">{{ config('quotation.customer_types.' . $quotationRequest->customer_type, $quotationRequest->customer_type) }}</dd>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cargo Information -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-purple-600 to-pink-600 p-6">
                <h3 class="text-2xl font-bold text-white">
                    <i class="fas fa-box mr-2"></i>Cargo Information
                </h3>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Description</dt>
                        <dd class="text-gray-900 mb-4">{{ $quotationRequest->cargo_description }}</dd>
                    </div>
                    
                    <div>
                        @if($quotationRequest->cargo_details && isset($quotationRequest->cargo_details['weight']))
                        <div class="mb-4">
                            <dt class="text-sm font-medium text-gray-500">Weight</dt>
                            <dd class="text-gray-900">{{ number_format($quotationRequest->cargo_details['weight'], 2) }} kg</dd>
                        </div>
                        @endif
                        
                        @if($quotationRequest->cargo_details && isset($quotationRequest->cargo_details['volume']))
                        <div class="mb-4">
                            <dt class="text-sm font-medium text-gray-500">Volume</dt>
                            <dd class="text-gray-900">{{ number_format($quotationRequest->cargo_details['volume'], 2) }} m³</dd>
                        </div>
                        @endif
                        
                        @if($quotationRequest->cargo_details && isset($quotationRequest->cargo_details['dimensions']))
                        <div class="mb-4">
                            <dt class="text-sm font-medium text-gray-500">Dimensions</dt>
                            <dd class="text-gray-900">{{ $quotationRequest->cargo_details['dimensions'] }}</dd>
                        </div>
                        @endif
                        
                        @if($quotationRequest->preferred_departure_date)
                        <div class="mb-4">
                            <dt class="text-sm font-medium text-gray-500">Preferred Departure</dt>
                            <dd class="text-gray-900">{{ \Carbon\Carbon::parse($quotationRequest->preferred_departure_date)->format('F j, Y') }}</dd>
                        </div>
                        @endif
                    </div>
                </div>
                
                @if($quotationRequest->cargo_details && isset($quotationRequest->cargo_details['special_requirements']))
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <dt class="text-sm font-medium text-gray-500 mb-2">Special Requirements</dt>
                    <dd class="text-gray-900">{{ $quotationRequest->cargo_details['special_requirements'] }}</dd>
                </div>
                @endif
            </div>
        </div>

        <!-- Uploaded Files -->
        @if($quotationRequest->files && $quotationRequest->files->count() > 0)
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 p-6">
                <h3 class="text-2xl font-bold text-white">
                    <i class="fas fa-paperclip mr-2"></i>Uploaded Documents
                </h3>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($quotationRequest->files as $file)
                    <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-file text-gray-400 text-2xl"></i>
                            </div>
                            <div class="ml-3 flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">
                                    {{ $file->original_name }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ number_format($file->file_size / 1024, 1) }} KB
                                </p>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <!-- Next Steps -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8">
            <h3 class="text-xl font-bold text-blue-900 mb-4">
                <i class="fas fa-info-circle mr-2"></i>What Happens Next?
            </h3>
            <div class="space-y-3 text-blue-800">
                <div class="flex items-start">
                    <div class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">1</div>
                    <p>Our logistics experts will review your request and prepare a detailed quotation.</p>
                </div>
                <div class="flex items-start">
                    <div class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">2</div>
                    <p>We will contact you within 24 hours via email or phone to discuss your requirements.</p>
                </div>
                <div class="flex items-start">
                    <div class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">3</div>
                    <p>You will receive a comprehensive quotation with pricing, timeline, and service details.</p>
                </div>
                <div class="flex items-start">
                    <div class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 mt-0.5">4</div>
                    <p>You can track your request status using the request number above.</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('public.quotations.status') }}?request_number={{ $quotationRequest->request_number }}" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold text-center transition-colors">
                <i class="fas fa-search mr-2"></i>
                Track Request Status
            </a>
            <a href="{{ auth()->check() ? route('customer.schedules.index') : route('public.schedules.index') }}" 
               class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-semibold text-center transition-colors">
                <i class="fas fa-calendar mr-2"></i>
                View Shipping Schedules
            </a>
            <a href="{{ route('public.quotations.create') }}" 
               class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold text-center transition-colors">
                <i class="fas fa-plus mr-2"></i>
                New Quotation Request
            </a>
        </div>
    </div>
</div>
@endsection
