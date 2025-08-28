@php
    $extractedData = $getState() ?? [];
    $documentData = $extractedData['document_data'] ?? [];
    $aiEnhancedData = $extractedData['ai_enhanced_data'] ?? [];
    $attribution = $extractedData['data_attribution'] ?? [];
    $metadata = $extractedData['metadata'] ?? [];
    
    // Calculate percentages
    $docFieldCount = count($attribution['document_fields'] ?? []);
    $aiFieldCount = count($attribution['ai_enhanced_fields'] ?? []);
    $totalFields = $docFieldCount + $aiFieldCount;
    $docPercentage = $totalFields > 0 ? round(($docFieldCount / $totalFields) * 100) : 100;
@endphp

<div class="extraction-results-display space-y-6">
    {{-- Header with confidence score --}}
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Extraction Results</h2>
        <div class="flex items-center gap-4">
            <div class="text-sm">
                <span class="text-gray-500">Confidence:</span>
                <span class="font-semibold {{ ($metadata['overall_confidence'] ?? 0) >= 0.8 ? 'text-green-600' : (($metadata['overall_confidence'] ?? 0) >= 0.5 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ round(($metadata['overall_confidence'] ?? 0) * 100) }}%
                </span>
            </div>
        </div>
    </div>

    {{-- Data Source Overview --}}
    <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Data Source Overview</h3>
        <div class="flex items-center gap-4">
            <div class="flex-1">
                <div class="flex justify-between text-xs mb-1">
                    <span class="text-gray-600 dark:text-gray-400">Document Data ({{ $docFieldCount }} fields)</span>
                    <span class="text-gray-600 dark:text-gray-400">AI Enhanced ({{ $aiFieldCount }} fields)</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-6 overflow-hidden">
                    <div class="h-full flex">
                        <div class="bg-blue-500 flex items-center justify-center text-white text-xs font-medium" style="width: {{ $docPercentage }}%">
                            {{ $docPercentage }}%
                        </div>
                        <div class="bg-yellow-500 flex items-center justify-center text-white text-xs font-medium" style="width: {{ 100 - $docPercentage }}%">
                            {{ 100 - $docPercentage }}%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Document Extracted Data --}}
    <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg overflow-hidden">
        <div class="bg-blue-50 dark:bg-blue-900/20 px-6 py-4 border-b border-blue-200 dark:border-blue-800">
            <h3 class="flex items-center text-lg font-semibold text-blue-800 dark:text-blue-200">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Data Extracted from Document
            </h3>
            <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">Information found in the uploaded document</p>
        </div>
        
        <div class="bg-white dark:bg-gray-800 p-6">
            @if(!empty($documentData['vehicle']))
                <div class="mb-6">
                    <h4 class="flex items-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 flex items-center justify-center mr-2 text-xs">🚗</span>
                        Vehicle Information
                    </h4>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($documentData['vehicle'] as $key => $value)
                            @if(!in_array($key, ['database_match', 'database_id', 'validation']) && !is_array($value))
                                <div class="flex">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400 w-32">✓ {{ ucwords(str_replace('_', ' ', $key)) }}:</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100 flex-1">{{ $value ?: 'Not specified' }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </div>
            @endif

            @if(!empty($documentData['shipping']) || !empty($documentData['shipment']))
                <div class="mb-6">
                    <h4 class="flex items-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 flex items-center justify-center mr-2 text-xs">🚢</span>
                        Shipping Information
                    </h4>
                    @php
                        $shippingData = array_merge($documentData['shipping'] ?? [], $documentData['shipment'] ?? []);
                    @endphp
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($shippingData as $key => $value)
                            @if(!is_array($value))
                                <div class="flex">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400 w-32">✓ {{ ucwords(str_replace('_', ' ', $key)) }}:</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100 flex-1">{{ $value ?: 'Not specified' }}</dd>
                                </div>
                            @elseif($key === 'route' && is_array($value))
                                <div class="col-span-full">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400 mb-2">✓ Route:</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100 ml-4">
                                        @if(!empty($value['origin']['city']))
                                            <div>Origin: {{ $value['origin']['city'] }}{{ !empty($value['origin']['country']) ? ', ' . $value['origin']['country'] : '' }}</div>
                                        @endif
                                        @if(!empty($value['destination']['city']))
                                            <div>Destination: {{ $value['destination']['city'] }}{{ !empty($value['destination']['country']) ? ', ' . $value['destination']['country'] : '' }}</div>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </div>
            @endif

            @if(!empty($documentData['contact']))
                <div class="mb-6">
                    <h4 class="flex items-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                        <span class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 flex items-center justify-center mr-2 text-xs">👤</span>
                        Contact Information
                    </h4>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach($documentData['contact'] as $key => $value)
                            @if(!is_array($value) && !str_starts_with($key, '_'))
                                <div class="flex">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400 w-32">✓ {{ ucwords(str_replace('_', ' ', $key)) }}:</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100 flex-1">{{ $value ?: 'Not specified' }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                </div>
            @endif

            @if(empty($documentData['vehicle']) && empty($documentData['shipping']) && empty($documentData['contact']))
                <p class="text-gray-500 dark:text-gray-400 text-sm">No structured data found in document</p>
            @endif
        </div>
    </div>

    {{-- AI Enhanced Data --}}
    <div class="border-2 border-yellow-300 dark:border-yellow-700 rounded-lg overflow-hidden">
        <div class="bg-yellow-50 dark:bg-yellow-900/20 px-6 py-4 border-b border-yellow-300 dark:border-yellow-700">
            <h3 class="flex items-center text-lg font-semibold text-yellow-800 dark:text-yellow-200">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                AI-Enhanced / Database-Added Data
            </h3>
            <p class="text-sm font-semibold text-yellow-700 dark:text-yellow-400 mt-1">⚠️ This information was NOT in the original document</p>
        </div>
        
        <div class="bg-yellow-50/50 dark:bg-yellow-900/10 p-6">
            @if(!empty($aiEnhancedData))
                @foreach($aiEnhancedData as $source => $data)
                    <div class="mb-6 last:mb-0">
                        <h4 class="text-sm font-semibold text-yellow-700 dark:text-yellow-300 mb-3">
                            ⚡ Enhanced from: {{ ucfirst(str_replace('_', ' ', $source)) }}
                        </h4>
                        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-yellow-200 dark:border-yellow-800">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach($data as $key => $value)
                                    @if(!is_array($value) && !in_array($key, ['confidence', 'source']))
                                        <div class="flex">
                                            <dt class="text-sm text-gray-500 dark:text-gray-400 w-32">
                                                <span class="text-yellow-600">➕</span> {{ ucwords(str_replace('_', ' ', $key)) }}:
                                            </dt>
                                            <dd class="text-sm font-medium text-gray-900 dark:text-gray-100 flex-1">{{ $value ?: 'Not specified' }}</dd>
                                        </div>
                                    @elseif($key === 'enhanced_fields' && is_array($value))
                                        @foreach($value as $field => $fieldValue)
                                            <div class="flex">
                                                <dt class="text-sm text-gray-500 dark:text-gray-400 w-32">
                                                    <span class="text-yellow-600">➕</span> {{ ucwords(str_replace('_', ' ', $field)) }}:
                                                </dt>
                                                <dd class="text-sm font-medium text-gray-900 dark:text-gray-100 flex-1">{{ $fieldValue ?: 'Not specified' }}</dd>
                                            </div>
                                        @endforeach
                                    @endif
                                @endforeach
                            </dl>
                            @if(isset($data['confidence']))
                                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                                    <span class="text-xs text-gray-500">Database Confidence: {{ round($data['confidence'] * 100) }}%</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @else
                <p class="text-green-600 dark:text-green-400 text-sm">✅ No AI enhancement was needed - all information was found in the document</p>
            @endif
        </div>
    </div>

    {{-- Metadata Section --}}
    @if(!empty($metadata))
        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-6">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">🔍 Extraction Metadata</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @if(isset($metadata['extraction_strategies']))
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">Strategies Used</dt>
                        <dd class="text-sm font-medium">{{ implode(' → ', $metadata['extraction_strategies']) }}</dd>
                    </div>
                @endif
                @if(isset($metadata['processing_time_ms']))
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">Processing Time</dt>
                        <dd class="text-sm font-medium">{{ round($metadata['processing_time_ms'], 2) }}ms</dd>
                    </div>
                @endif
                @if(isset($metadata['strategy_used']))
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">Primary Strategy</dt>
                        <dd class="text-sm font-medium">{{ $metadata['strategy_used'] }}</dd>
                    </div>
                @endif
                @if(isset($metadata['overall_confidence']))
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">Overall Confidence</dt>
                        <dd class="text-sm font-medium {{ ($metadata['overall_confidence'] ?? 0) >= 0.8 ? 'text-green-600' : (($metadata['overall_confidence'] ?? 0) >= 0.5 ? 'text-yellow-600' : 'text-red-600') }}">
                            {{ round(($metadata['overall_confidence'] ?? 0) * 100) }}%
                        </dd>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
