@props(['schedule'])

<div class="bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow duration-150">
    <div class="p-6">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <!-- Route -->
                <div class="flex items-center space-x-2 mb-3">
                    <span class="text-sm font-medium text-gray-900">{{ $schedule->polPort->name }}</span>
                    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                    <span class="text-sm font-medium text-gray-900">{{ $schedule->podPort->name }}</span>
                </div>

                <!-- Carrier & Service -->
                <div class="flex items-center space-x-4 mb-4">
                    <div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            {{ $schedule->carrier->name }}
                        </span>
                    </div>
                    @if($schedule->carrier->service_types)
                        @php
                            $serviceTypes = is_array($schedule->carrier->service_types) 
                                ? $schedule->carrier->service_types 
                                : (is_string($schedule->carrier->service_types) ? [$schedule->carrier->service_types] : []);
                        @endphp
                        @foreach($serviceTypes as $serviceType)
                            @php
                                // Handle nested arrays with 'name' key (from config)
                                $displayName = is_array($serviceType) && isset($serviceType['name']) 
                                    ? $serviceType['name'] 
                                    : (is_string($serviceType) ? str_replace('_', ' ', $serviceType) : '');
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                {{ $displayName }}
                            </span>
                        @endforeach
                    @endif
                </div>

                <!-- Details Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- Vessel -->
                    @if($schedule->vessel_name)
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Vessel</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $schedule->vessel_name }}</dd>
                    </div>
                    @endif

                    <!-- Next Sailing -->
                    @if($schedule->next_sailing_date)
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Next Sailing</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $schedule->next_sailing_date->format('M d, Y') }}</dd>
                    </div>
                    @endif

                    <!-- Transit Time -->
                    @if($schedule->transit_days)
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Transit Time</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $schedule->transit_time_display }}</dd>
                    </div>
                    @endif

                    <!-- Frequency -->
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Frequency</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $schedule->accurate_frequency_display }}</dd>
                    </div>

                    <!-- ETS POL -->
                    @if($schedule->ets_pol)
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Departure (ETD)</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $schedule->ets_pol->format('M d, Y') }}</dd>
                    </div>
                    @endif

                    <!-- ETA POD -->
                    @if($schedule->eta_pod)
                    <div>
                        <dt class="text-xs font-medium text-gray-500">Arrival (ETA)</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $schedule->eta_pod->format('M d, Y') }}</dd>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Actions -->
            <div class="ml-6 flex-shrink-0">
                <div class="flex flex-col space-y-2">
                    <a href="{{ route('public.schedules.show', $schedule) }}" 
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        View Details
                    </a>
                    <a href="{{ route('public.quotations.create', [
                        'pol' => $schedule->polPort->name,
                        'pod' => $schedule->podPort->name,
                        'service_type' => $schedule->carrier->service_types ? $schedule->carrier->service_types[0] : null,
                        'carrier' => $schedule->carrier->name
                    ]) }}" 
                       class="inline-flex items-center px-4 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Request Quote
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

