@extends('public.quotations.layout')

@section('title', 'Quotation Request Status - Belgaco Logistics')

@section('content')
<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Status Header -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            Request Status
                        </h1>
                        <p class="text-blue-100">
                            Request Number: {{ $quotationRequest->request_number }}
                        </p>
                    </div>
                    <div class="text-right">
                        @switch($quotationRequest->status)
                            @case('pending')
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-yellow-100 text-yellow-800">
                                    <i class="fas fa-clock mr-2"></i>
                                    Pending Review
                                </span>
                                @break
                            @case('processing')
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-blue-100 text-blue-800">
                                    <i class="fas fa-cog fa-spin mr-2"></i>
                                    Processing
                                </span>
                                @break
                            @case('quoted')
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    Quoted
                                </span>
                                @break
                            @case('accepted')
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-green-100 text-green-800">
                                    <i class="fas fa-thumbs-up mr-2"></i>
                                    Accepted
                                </span>
                                @break
                            @case('rejected')
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-red-100 text-red-800">
                                    <i class="fas fa-times-circle mr-2"></i>
                                    Declined
                                </span>
                                @break
                            @case('expired')
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-gray-100 text-gray-800">
                                    <i class="fas fa-hourglass-end mr-2"></i>
                                    Expired
                                </span>
                                @break
                            @default
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-semibold bg-gray-100 text-gray-800">
                                    <i class="fas fa-question-circle mr-2"></i>
                                    Unknown Status
                                </span>
                        @endswitch
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Timeline -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-green-600 to-blue-600 p-6">
                <h2 class="text-2xl font-bold text-white">
                    <i class="fas fa-tasks mr-2"></i>Progress Timeline
                </h2>
            </div>
            
            <div class="p-6">
                <div class="flow-root">
                    <ul class="-mb-8">
                        <li>
                            <div class="relative pb-8">
                                <div class="relative flex space-x-3">
                                    <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                        <i class="fas fa-check text-white text-sm"></i>
                                    </div>
                                    <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Request Submitted</p>
                                            <p class="text-sm text-gray-500">Your quotation request has been received</p>
                                        </div>
                                        <div class="whitespace-nowrap text-right text-sm text-gray-500">
                                            {{ $quotationRequest->created_at->format('M j, Y') }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                        
                        <li>
                            <div class="relative pb-8">
                                <div class="relative flex space-x-3">
                                    @if(in_array($quotationRequest->status, ['processing', 'quoted', 'accepted', 'rejected', 'expired']))
                                        <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                            <i class="fas fa-check text-white text-sm"></i>
                                        </div>
                                    @else
                                        <div class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center ring-8 ring-white">
                                            <i class="fas fa-clock text-gray-600 text-sm"></i>
                                        </div>
                                    @endif
                                    <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Under Review</p>
                                            <p class="text-sm text-gray-500">Our experts are analyzing your requirements</p>
                                        </div>
                                        <div class="whitespace-nowrap text-right text-sm text-gray-500">
                                            @if(in_array($quotationRequest->status, ['processing', 'quoted', 'accepted', 'rejected', 'expired']))
                                                {{ $quotationRequest->updated_at->format('M j, Y') }}
                                            @else
                                                Pending
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                        
                        <li>
                            <div class="relative pb-8">
                                <div class="relative flex space-x-3">
                                    @if(in_array($quotationRequest->status, ['quoted', 'accepted', 'rejected', 'expired']))
                                        <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                            <i class="fas fa-check text-white text-sm"></i>
                                        </div>
                                    @else
                                        <div class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center ring-8 ring-white">
                                            <i class="fas fa-clock text-gray-600 text-sm"></i>
                                        </div>
                                    @endif
                                    <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Quotation Prepared</p>
                                            <p class="text-sm text-gray-500">Your detailed quotation is ready</p>
                                        </div>
                                        <div class="whitespace-nowrap text-right text-sm text-gray-500">
                                            @if(in_array($quotationRequest->status, ['quoted', 'accepted', 'rejected', 'expired']))
                                                {{ $quotationRequest->updated_at->format('M j, Y') }}
                                            @else
                                                Pending
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                        
                        <li>
                            <div class="relative">
                                <div class="relative flex space-x-3">
                                    @if(in_array($quotationRequest->status, ['accepted']))
                                        <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                            <i class="fas fa-check text-white text-sm"></i>
                                        </div>
                                    @else
                                        <div class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center ring-8 ring-white">
                                            <i class="fas fa-clock text-gray-600 text-sm"></i>
                                        </div>
                                    @endif
                                    <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Booking Confirmed</p>
                                            <p class="text-sm text-gray-500">Your shipment is confirmed and scheduled</p>
                                        </div>
                                        <div class="whitespace-nowrap text-right text-sm text-gray-500">
                                            @if($quotationRequest->status === 'accepted')
                                                {{ $quotationRequest->updated_at->format('M j, Y') }}
                                            @else
                                                Pending
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Request Summary -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-purple-600 to-pink-600 p-6">
                <h2 class="text-2xl font-bold text-white">
                    <i class="fas fa-info-circle mr-2"></i>Request Summary
                </h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Route Information</h4>
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Route</dt>
                                <dd class="text-gray-900">
                                    @if($quotationRequest->por)
                                        {{ $quotationRequest->por }} → 
                                    @endif
                                    {{ $quotationRequest->pol }} → {{ $quotationRequest->pod }}
                                    @if($quotationRequest->fdest)
                                        → {{ $quotationRequest->fdest }}
                                    @endif
                                    @if($quotationRequest->in_transit_to)
                                        → (In Transit To: {{ $quotationRequest->in_transit_to }})
                                    @endif
                                </dd>
                            </div>
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
                        </dl>
                    </div>
                    
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Cargo Information</h4>
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="text-gray-900">{{ $quotationRequest->cargo_description }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Commodity Type</dt>
                                <dd class="text-gray-900 capitalize">{{ str_replace('_', ' ', $quotationRequest->commodity_type) }}</dd>
                            </div>
                            @if($quotationRequest->cargo_details && isset($quotationRequest->cargo_details['weight']))
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Weight</dt>
                                <dd class="text-gray-900">{{ number_format($quotationRequest->cargo_details['weight'], 2) }} kg</dd>
                            </div>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Messages -->
        @switch($quotationRequest->status)
            @case('pending')
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-8">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-clock text-yellow-400 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-yellow-800">Your request is pending review</h3>
                            <div class="mt-2 text-yellow-700">
                                <p>We have received your quotation request and it's currently being reviewed by our logistics experts. You can expect to hear from us within 24 hours.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @break
                
            @case('processing')
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-cog fa-spin text-blue-400 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-blue-800">Your quotation is being prepared</h3>
                            <div class="mt-2 text-blue-700">
                                <p>Our team is working on your quotation and will contact you soon with detailed pricing and service options.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @break
                
            @case('quoted')
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-green-800">Your quotation is ready!</h3>
                            <div class="mt-2 text-green-700">
                                <p>We have prepared your detailed quotation. Please check your email for the complete pricing and service details. If you haven't received it, please contact us.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @break
                
            @case('accepted')
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-thumbs-up text-green-400 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-green-800">Quotation accepted!</h3>
                            <div class="mt-2 text-green-700">
                                <p>Thank you for accepting our quotation. Our team will now proceed with the booking process and will contact you with further details.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @break
                
            @case('rejected')
                <div class="bg-red-50 border border-red-200 rounded-lg p-6 mb-8">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-times-circle text-red-400 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-red-800">Quotation declined</h3>
                            <div class="mt-2 text-red-700">
                                <p>We understand that our quotation didn't meet your requirements. Please feel free to submit a new request with updated specifications.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @break
                
            @case('expired')
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 mb-8">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-hourglass-end text-gray-400 text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-medium text-gray-800">Quotation expired</h3>
                            <div class="mt-2 text-gray-700">
                                <p>This quotation has expired. Please submit a new request to get updated pricing and availability.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @break
        @endswitch

        <!-- Contact Information -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden mb-8">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 p-6">
                <h2 class="text-2xl font-bold text-white">
                    <i class="fas fa-headset mr-2"></i>Need Assistance?
                </h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="text-center">
                        <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-phone text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Call Us</h3>
                        <p class="text-gray-600">+32 3 123 45 67</p>
                        <p class="text-sm text-gray-500">Mon-Fri: 9:00-17:00 CET</p>
                    </div>
                    
                    <div class="text-center">
                        <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-envelope text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Email Us</h3>
                        <p class="text-gray-600">info@belgaco.com</p>
                        <p class="text-sm text-gray-500">We'll respond within 24 hours</p>
                    </div>
                    
                    <div class="text-center">
                        <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-comments text-purple-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Live Chat</h3>
                        <p class="text-gray-600">Available on our website</p>
                        <p class="text-sm text-gray-500">Instant support</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('public.quotations.status') }}" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold text-center transition-colors">
                <i class="fas fa-search mr-2"></i>
                Track Another Request
            </a>
            <a href="{{ route('public.schedules.index') }}" 
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
