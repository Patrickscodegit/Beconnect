@extends('public.schedules.layout')

@section('title', 'Shipping Schedules')
@section('meta_description', 'Browse real-time shipping schedules for RORO, FCL, LCL, and Break Bulk services. Filter by port, carrier, and service type.')

@section('content')
<div class="py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Shipping Schedules</h1>
            <p class="mt-2 text-gray-600">Find the perfect shipping schedule for your cargo. Filter by port, carrier, and service type.</p>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
            <form method="GET" action="{{ route('public.schedules.index') }}" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- POL Filter -->
                    <div>
                        <label for="pol" class="block text-sm font-medium text-gray-700 mb-2">
                            Port of Loading
                        </label>
                        <select name="pol" id="pol" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <option value="">All Ports</option>
                            @foreach($polPorts as $port)
                                <option value="{{ $port->code }}" {{ $filters['pol'] == $port->code ? 'selected' : '' }}>
                                    {{ $port->name }} ({{ $port->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- POD Filter -->
                    <div>
                        <label for="pod" class="block text-sm font-medium text-gray-700 mb-2">
                            Port of Discharge
                        </label>
                        <select name="pod" id="pod" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <option value="">All Ports</option>
                            @foreach($podPorts as $port)
                                <option value="{{ $port->code }}" {{ $filters['pod'] == $port->code ? 'selected' : '' }}>
                                    {{ $port->name }} ({{ $port->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Service Type Filter -->
                    <div>
                        <label for="service_type" class="block text-sm font-medium text-gray-700 mb-2">
                            Service Type
                        </label>
                        <select name="service_type" id="service_type" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <option value="">All Services</option>
                            @foreach($serviceTypes as $code => $name)
                                <option value="{{ $code }}" {{ $filters['service_type'] == $code ? 'selected' : '' }}>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Carrier Filter -->
                    <div>
                        <label for="carrier" class="block text-sm font-medium text-gray-700 mb-2">
                            Carrier
                        </label>
                        <select name="carrier" id="carrier" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <option value="">All Carriers</option>
                            @foreach($carriers as $carrier)
                                <option value="{{ $carrier->id }}" {{ $filters['carrier'] == $carrier->id ? 'selected' : '' }}>
                                    {{ $carrier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-4">
                    <a href="{{ route('public.schedules.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                        Clear filters
                    </a>
                    <button type="submit" 
                            class="inline-flex items-center px-4 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Results -->
        @if($schedules->count() > 0)
            <div class="mb-4 text-sm text-gray-600">
                Showing {{ $schedules->firstItem() }} to {{ $schedules->lastItem() }} of {{ $schedules->total() }} schedules
            </div>

            <!-- Schedule Cards -->
            <div class="space-y-4">
                @foreach($schedules as $schedule)
                    <x-schedule-card :schedule="$schedule" />
                @endforeach
            </div>

            <!-- Pagination -->
            <div class="mt-6">
                {{ $schedules->appends($filters)->links() }}
            </div>
        @else
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No schedules found</h3>
                <p class="mt-1 text-sm text-gray-500">Try adjusting your filters to find more results.</p>
                <div class="mt-6">
                    <a href="{{ route('public.schedules.index') }}" 
                       class="inline-flex items-center px-4 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 transition ease-in-out duration-150">
                        Clear All Filters
                    </a>
                </div>
            </div>
        @endif

        <!-- Request Quotation CTA -->
        <div id="request-quote" class="mt-12 bg-amber-50 rounded-lg border border-amber-200 p-8">
            <div class="text-center">
                <h2 class="text-2xl font-bold text-gray-900">Need a Custom Quote?</h2>
                <p class="mt-2 text-gray-600">Get personalized pricing for your specific shipping needs.</p>
                <div class="mt-6">
                    <a href="#" 
                       class="inline-flex items-center px-6 py-3 bg-amber-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Request Quotation
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

