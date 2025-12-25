@extends('customer.layout')

@section('title', $schedule->polPort->name . ' → ' . $schedule->podPort->name . ' - ' . $schedule->carrier->name)
@section('meta_description', 'Shipping schedule from ' . $schedule->polPort->name . ' to ' . $schedule->podPort->name . ' via ' . $schedule->carrier->name . '. View departure dates, transit times, and request a quotation.')

@section('content')
<div class="py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="{{ route('public.schedules.index') }}" 
               class="inline-flex items-center text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                Back to Schedules
            </a>
        </div>

        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 mb-6">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <!-- Route Visualization -->
                    <div class="flex items-center space-x-4 mb-6">
                        <div class="text-center">
                            <div class="text-sm font-medium text-gray-500">From</div>
                            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $schedule->polPort->name }}</div>
                            <div class="text-xs text-gray-500 mt-1">{{ $schedule->polPort->country }}</div>
                        </div>
                        
                        <div class="flex-1 flex items-center justify-center">
                            <div class="border-t-2 border-amber-500 w-full relative">
                                <svg class="w-6 h-6 text-amber-500 absolute right-0 top-1/2 transform -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <div class="text-sm font-medium text-gray-500">To</div>
                            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $schedule->podPort->name }}</div>
                            <div class="text-xs text-gray-500 mt-1">{{ $schedule->podPort->country }}</div>
                        </div>
                    </div>

                    <!-- Carrier Badge -->
                    <div class="flex items-center space-x-3 mb-4">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                            {{ $schedule->carrier->name }}
                        </span>
                        @if($schedule->carrier->service_types)
                            @foreach($schedule->carrier->service_types as $serviceType)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    {{ $serviceType }}
                                </span>
                            @endforeach
                        @endif
                    </div>
                </div>

                <!-- CTA -->
                <div class="ml-6">
                    <a href="{{ route('public.schedules.index') }}#request-quote" 
                       class="inline-flex items-center px-6 py-3 bg-amber-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Request Quotation
                    </a>
                </div>
            </div>
        </div>

        <!-- Schedule Details -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Schedule Details</h2>
            
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                <!-- Vessel Name -->
                @if($schedule->vessel_name)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Vessel / Vehicle</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->vessel_name }}</dd>
                </div>
                @endif

                <!-- Voyage Number -->
                @if($schedule->voyage_number)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Voyage Number</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->voyage_number }}</dd>
                </div>
                @endif

                <!-- Next Sailing -->
                @if($schedule->next_sailing_date)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Next Sailing</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->next_sailing_date->format('l, F j, Y') }}</dd>
                </div>
                @endif

                <!-- Frequency -->
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Service Frequency</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $frequency_display }}</dd>
                </div>

                <!-- ETD -->
                @if($schedule->ets_pol)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Estimated Time of Departure (ETD)</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->ets_pol->format('M d, Y') }}</dd>
                </div>
                @endif

                <!-- ETA -->
                @if($schedule->eta_pod)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Estimated Time of Arrival (ETA)</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->eta_pod->format('M d, Y') }}</dd>
                </div>
                @endif

                <!-- Transit Time -->
                @if($schedule->transit_days)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Transit Time</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $transit_time_display }}</dd>
                </div>
                @endif

                <!-- Service Name -->
                @if($schedule->service_name)
                <div class="border-b border-gray-100 pb-4">
                    <dt class="text-sm font-medium text-gray-500">Service Name</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->service_name }}</dd>
                </div>
                @endif
            </dl>
        </div>

        <!-- Carrier Information -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Carrier Information</h2>
            
            <dl class="space-y-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Carrier Name</dt>
                    <dd class="mt-1 text-base text-gray-900">{{ $schedule->carrier->name }}</dd>
                </div>

                @if($schedule->carrier->website_url)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Website</dt>
                    <dd class="mt-1 text-base">
                        <a href="{{ $schedule->carrier->website_url }}" target="_blank" rel="noopener" class="text-amber-600 hover:text-amber-700">
                            {{ $schedule->carrier->website_url }}
                        </a>
                    </dd>
                </div>
                @endif

                @if($schedule->carrier->specialization)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Specialization</dt>
                    <dd class="mt-1">
                        <div class="flex flex-wrap gap-2">
                            @php
                                $spec = is_array($schedule->carrier->specialization) 
                                    ? $schedule->carrier->specialization 
                                    : (is_string($schedule->carrier->specialization) 
                                        ? json_decode($schedule->carrier->specialization, true) 
                                        : []);
                            @endphp
                            @foreach($spec as $key => $value)
                                @if($value === true)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        {{ str_replace('_', ' ', $key) }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </dd>
                </div>
                @endif
            </dl>
        </div>

        <!-- CTA Bottom -->
        <div class="bg-amber-50 rounded-lg border border-amber-200 p-8 text-center">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Ready to Ship?</h3>
            <p class="text-gray-600 mb-6">Get a personalized quotation for this route and schedule.</p>
            <a href="{{ route('customer.quotations.create', [
                'pol' => $schedule->polPort->name,
                'pod' => $schedule->podPort->name,
                'service_type' => $schedule->carrier->service_types ? $schedule->carrier->service_types[0] : null,
                'carrier' => $schedule->carrier->code,
                'selected_schedule_id' => $schedule->id
            ]) }}" 
               class="inline-flex items-center px-6 py-3 bg-amber-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Request Quotation for This Schedule
            </a>
        </div>
    </div>
</div>
@endsection

