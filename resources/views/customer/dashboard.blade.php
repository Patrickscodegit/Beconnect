@extends('customer.layout')

@section('title', 'Dashboard')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    
    <!-- Welcome Section -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">
            Welcome back, {{ $user->name }}!
        </h1>
        <p class="mt-2 text-gray-600">
            Manage your quotations and track your shipments all in one place.
        </p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-blue-500 rounded-md p-3">
                    <i class="fas fa-file-invoice text-white text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Quotations</p>
                    <p class="text-2xl font-semibold text-gray-900">{{ $stats['total'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
                    <i class="fas fa-clock text-white text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Pending</p>
                    <p class="text-2xl font-semibold text-gray-900">{{ $stats['pending'] + $stats['processing'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                    <i class="fas fa-check-circle text-white text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Quoted</p>
                    <p class="text-2xl font-semibold text-gray-900">{{ $stats['quoted'] }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-purple-500 rounded-md p-3">
                    <i class="fas fa-thumbs-up text-white text-2xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Accepted</p>
                    <p class="text-2xl font-semibold text-gray-900">{{ $stats['accepted'] }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <a href="{{ route('customer.quotations.create') }}" 
           class="bg-gradient-to-r from-blue-600 to-blue-700 rounded-lg shadow-lg p-8 text-white hover:from-blue-700 hover:to-blue-800 transition-all duration-300 transform hover:scale-105">
            <div class="flex items-center">
                <i class="fas fa-plus-circle text-4xl mr-4"></i>
                <div>
                    <h3 class="text-xl font-bold">Request New Quotation</h3>
                    <p class="text-blue-100 mt-1">Get a competitive quote for your shipment</p>
                </div>
            </div>
        </a>

        <a href="{{ route('customer.schedules.index') }}" 
           class="bg-gradient-to-r from-amber-600 to-amber-700 rounded-lg shadow-lg p-8 text-white hover:from-amber-700 hover:to-amber-800 transition-all duration-300 transform hover:scale-105">
            <div class="flex items-center">
                <i class="fas fa-ship text-4xl mr-4"></i>
                <div>
                    <h3 class="text-xl font-bold">View Sailing Schedules</h3>
                    <p class="text-amber-100 mt-1">Check departure dates and transit times</p>
                </div>
            </div>
        </a>
    </div>

    <!-- Robaws Profile & Offers -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6 lg:col-span-1">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-building mr-2"></i>Robaws Company Profile
            </h2>

            @if($robawsLink && $robawsProfile)
                <div class="space-y-2">
                    <p class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">Company:</span>
                        {{ data_get($robawsProfile, 'name', 'N/A') }}
                    </p>
                    <p class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">Email:</span>
                        {{ data_get($robawsProfile, 'email', 'N/A') }}
                    </p>
                    <p class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">Phone:</span>
                        {{ data_get($robawsProfile, 'tel', 'N/A') }}
                    </p>
                    <p class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">Address:</span>
                        {{ data_get($robawsProfile, 'address.addressLine1', 'N/A') }}
                        @if(data_get($robawsProfile, 'address.city'))
                            , {{ data_get($robawsProfile, 'address.city') }}
                        @endif
                        @if(data_get($robawsProfile, 'address.country'))
                            , {{ data_get($robawsProfile, 'address.country') }}
                        @endif
                    </p>
                </div>
            @else
                <div class="text-sm text-gray-500">
                    Robaws profile not linked yet for this account.
                </div>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow lg:col-span-2">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-file-pdf mr-2"></i>Robaws Quotations
                </h2>
            </div>

            @if($robawsLink && count($robawsOffers) > 0)
                <div class="divide-y divide-gray-200">
                    @foreach($robawsOffers as $offer)
                        @php
                            $offerId = data_get($offer, 'id');
                            $offerNumber = data_get($offer, 'display_number')
                                ?? data_get($offer, 'offerNumber')
                                ?? data_get($offer, 'number')
                                ?? $offerId;
                            $bconnectNumber = data_get($offer, 'bconnect_request_number');
                            $routeDisplay = data_get($offer, 'route_display');
                            $cargoSummary = data_get($offer, 'cargo_summary');
                            $offerStatus = data_get($offer, 'status', 'unknown');
                            $offerUpdated = data_get($offer, 'updatedAt')
                                ?? data_get($offer, 'updated_at');
                        @endphp
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ $offerNumber }}</div>
                                @if($bconnectNumber)
                                    <div class="text-xs text-gray-500">Bconnect: {{ $bconnectNumber }}</div>
                                @endif
                                <div class="text-xs text-gray-500">Status: {{ $offerStatus }}</div>
                                @if($routeDisplay)
                                    <div class="text-xs text-gray-500">Route: {{ $routeDisplay }}</div>
                                @endif
                                @if($cargoSummary)
                                    <div class="text-xs text-gray-500">Cargo: {{ $cargoSummary }}</div>
                                @endif
                                @if($offerUpdated)
                                    <div class="text-xs text-gray-500">Updated: {{ $offerUpdated }}</div>
                                @endif
                            </div>
                            @if($offerId && Route::has('customer.robaws.offers.pdf'))
                                <a href="{{ route('customer.robaws.offers.pdf', $offerId) }}"
                                   target="_blank"
                                   class="inline-flex items-center px-3 py-2 bg-red-600 text-white rounded-md text-xs font-medium hover:bg-red-700">
                                    Open PDF
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="px-6 py-6 text-sm text-gray-500">
                    No Robaws quotations available yet.
                </div>
            @endif
        </div>
    </div>

    <!-- Recent Quotations -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-history mr-2"></i>Recent Quotations
                </h2>
                <a href="{{ route('customer.quotations.index') }}" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
        
        @if($recentQuotations->count() > 0)
            <div class="divide-y divide-gray-200">
                @foreach($recentQuotations as $quotation)
                    <div class="px-6 py-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <div class="flex items-center">
                                    <span class="text-lg font-semibold text-gray-900">{{ $quotation->request_number }}</span>
                                    <span class="ml-3 px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($quotation->status === 'pending') bg-yellow-100 text-yellow-800
                                        @elseif($quotation->status === 'processing') bg-blue-100 text-blue-800
                                        @elseif($quotation->status === 'quoted') bg-green-100 text-green-800
                                        @elseif($quotation->status === 'accepted') bg-purple-100 text-purple-800
                                        @elseif($quotation->status === 'rejected') bg-red-100 text-red-800
                                        @else bg-gray-100 text-gray-800
                                        @endif">
                                        {{ ucfirst($quotation->status) }}
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600 mt-1">
                                    <i class="fas fa-route mr-1"></i>
                                    {{ $quotation->pol ?? 'N/A' }} → {{ $quotation->pod ?? 'N/A' }}
                                </p>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-calendar mr-1"></i>
                                    {{ $quotation->created_at->format('M d, Y') }}
                                </p>
                            </div>
                            <div class="text-right">
                                @if($quotation->total_incl_vat)
                                    <p class="text-lg font-bold text-gray-900">
                                        €{{ number_format($quotation->total_incl_vat, 2) }}
                                    </p>
                                @endif
                                <a href="{{ route('customer.quotations.show', $quotation) }}" 
                                   class="text-blue-600 hover:text-blue-800 text-sm font-medium mt-2 inline-block">
                                    View Details <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="px-6 py-12 text-center">
                <i class="fas fa-inbox text-gray-300 text-5xl mb-4"></i>
                <p class="text-gray-500">No quotations yet.</p>
                <a href="{{ route('customer.quotations.create') }}" 
                   class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <i class="fas fa-plus mr-2"></i>Request Your First Quotation
                </a>
            </div>
        @endif
    </div>
</div>
@endsection

