<div class="space-y-6">
    <!-- Extraction Metadata -->
    <div class="bg-gray-50 rounded-lg p-4">
        <div class="grid grid-cols-3 gap-4 text-sm">
            <div>
                <span class="font-medium text-gray-700">Confidence:</span>
                <span class="ml-2 text-gray-900">{{ number_format($extraction->confidence * 100, 1) }}%</span>
            </div>
            <div>
                <span class="font-medium text-gray-700">Extracted:</span>
                <span class="ml-2 text-gray-900">{{ $extraction->created_at->format('M j, Y g:i A') }}</span>
            </div>
            <div>
                <span class="font-medium text-gray-700">Status:</span>
                @if($extraction->verified_at)
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        Verified
                    </span>
                @else
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                        Unverified
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Extracted Data -->
    <div class="space-y-4">
        <h4 class="text-lg font-medium text-gray-900">Extracted Information</h4>
        
        @if($extraction->raw_json)
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-4 py-5 sm:p-6">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                        @php
                            $rawData = is_string($extraction->raw_json) ? json_decode($extraction->raw_json, true) : $extraction->raw_json;
                        @endphp
                        @if(is_array($rawData))
                            @foreach($rawData as $key => $value)
                            <div>
                                <dt class="text-sm font-medium text-gray-500 capitalize">
                                    {{ str_replace('_', ' ', $key) }}
                                </dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    @if(is_array($value))
                                        <ul class="list-disc list-inside space-y-1">
                                            @foreach($value as $item)
                                                <li>{{ is_string($item) ? $item : json_encode($item) }}</li>
                                            @endforeach
                                        </ul>
                                    @elseif(is_bool($value))
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $value ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $value ? 'Yes' : 'No' }}
                                        </span>
                                    @elseif(is_null($value))
                                        <span class="text-gray-400 italic">Not available</span>
                                    @else
                                        {{ $value }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                        @else
                            <div class="col-span-2">
                                <p class="text-sm text-gray-500">No extraction data available</p>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        @else
            <div class="text-center text-gray-500 py-8">
                <p>No extracted data available.</p>
            </div>
        @endif
    </div>

    <!-- Raw JSON (collapsible) -->
    <div class="border-t pt-4">
        <details class="group">
            <summary class="flex cursor-pointer items-center justify-between rounded-lg bg-gray-50 p-4 text-gray-900">
                <h5 class="font-medium">Raw JSON Data</h5>
                <svg class="h-5 w-5 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>
            <div class="mt-4 rounded-lg bg-gray-900 p-4">
                <pre class="text-sm text-green-400 overflow-x-auto"><code>{{ json_encode($extraction->raw_json, JSON_PRETTY_PRINT) }}</code></pre>
            </div>
        </details>
    </div>
</div>
