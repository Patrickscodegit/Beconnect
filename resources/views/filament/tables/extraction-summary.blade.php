@php
    $data = $getState();
    if (!$data) {
        echo '<span class="text-gray-500 text-sm">No extraction data</span>';
        return;
    }
    
    $documentData = $data['document_data'] ?? [];
    $aiData = $data['ai_enhanced_data'] ?? [];
    $attribution = $data['data_attribution'] ?? [];
    $metadata = $data['metadata'] ?? [];
    
    // Quick summary data
    $vehicle = $documentData['vehicle'] ?? [];
    $shipping = array_merge($documentData['shipping'] ?? [], $documentData['shipment'] ?? []);
    $contact = $documentData['contact'] ?? [];
@endphp

<div class="space-y-2 max-w-md">
    {{-- Quick Summary --}}
    <div class="space-y-1">
        @if(!empty($vehicle))
            <div class="flex items-start gap-2">
                <span class="text-xs font-medium text-gray-500 w-16 flex-shrink-0">🚗 Vehicle:</span>
                <span class="text-xs text-gray-900 dark:text-gray-100">
                    {{ $vehicle['make'] ?? 'N/A' }} {{ $vehicle['model'] ?? '' }}
                    @if(!empty($vehicle['year'])) ({{ $vehicle['year'] }})@endif
                </span>
            </div>
        @endif
        
        @if(!empty($shipping))
            <div class="flex items-start gap-2">
                <span class="text-xs font-medium text-gray-500 w-16 flex-shrink-0">🚢 Route:</span>
                <span class="text-xs text-gray-900 dark:text-gray-100">
                    @if(!empty($shipping['route']['origin']['city']))
                        {{ $shipping['route']['origin']['city'] }}
                    @elseif(!empty($shipping['origin']))
                        {{ $shipping['origin'] }}
                    @else
                        N/A
                    @endif
                    →
                    @if(!empty($shipping['route']['destination']['city']))
                        {{ $shipping['route']['destination']['city'] }}
                    @elseif(!empty($shipping['destination']))
                        {{ $shipping['destination'] }}
                    @else
                        N/A
                    @endif
                </span>
            </div>
        @endif
        
        @if(!empty($contact))
            <div class="flex items-start gap-2">
                <span class="text-xs font-medium text-gray-500 w-16 flex-shrink-0">👤 Contact:</span>
                <span class="text-xs text-gray-900 dark:text-gray-100">
                    {{ $contact['name'] ?? 'N/A' }}
                    @if(!empty($contact['email'])) <br><span class="text-gray-600">{{ $contact['email'] }}</span>@endif
                </span>
            </div>
        @endif
    </div>
    
    {{-- Data Attribution Bar --}}
    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
        @php
            $docCount = count($attribution['document_fields'] ?? []);
            $aiCount = count($attribution['ai_enhanced_fields'] ?? []);
            $total = $docCount + $aiCount;
            $docPercent = $total > 0 ? round(($docCount / $total) * 100) : 100;
        @endphp
        
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500">Data:</span>
            <div class="flex-1 flex items-center gap-1">
                <div class="h-2 flex-1 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
                    <div class="bg-blue-500" style="width: {{ $docPercent }}%"></div>
                    <div class="bg-yellow-500" style="width: {{ 100 - $docPercent }}%"></div>
                </div>
                <span class="text-xs text-gray-600 dark:text-gray-400">
                    {{ $docPercent }}% doc
                </span>
            </div>
        </div>
        
        @if(isset($metadata['overall_confidence']))
            <div class="flex items-center gap-2 mt-1">
                <span class="text-xs text-gray-500">Confidence:</span>
                <span class="text-xs font-medium {{ $metadata['overall_confidence'] >= 0.8 ? 'text-green-600' : ($metadata['overall_confidence'] >= 0.5 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ round($metadata['overall_confidence'] * 100) }}%
                </span>
            </div>
        @endif
    </div>
    
    {{-- View Details Link --}}
    @if($getRecord() && $getRecord()->documents()->exists())
        <div class="pt-1">
            <a href="{{ route('filament.admin.resources.documents.view', ['record' => $getRecord()->documents()->latest()->first()->id]) }}" 
               class="text-xs text-primary-600 hover:text-primary-700 font-medium">
                View full extraction details →
            </a>
        </div>
    @endif
</div>
