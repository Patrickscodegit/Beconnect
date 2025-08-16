<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extraction Results - Intake #{{ $intake->id }}</title>
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Extraction Results</h1>
            <p class="text-gray-600">Intake #{{ $intake->id }} - {{ $intake->created_at->format('M j, Y g:i A') }}</p>
            
            <!-- Status Badge -->
            <div class="mt-4">
                <span class="px-3 py-1 rounded-full text-sm font-medium
                    @if($intake->status === 'rules_applied')
                        @if($statusInfo['all_verified'] ?? false)
                            bg-green-100 text-green-800
                        @else
                            bg-yellow-100 text-yellow-800
                        @endif
                    @else
                        bg-blue-100 text-blue-800
                    @endif">
                    {{ ucfirst(str_replace('_', ' ', $intake->status)) }}
                    @if($intake->status === 'rules_applied')
                        @if($statusInfo['all_verified'] ?? false)
                            ✓ Verified
                        @else
                            ⚠ Needs Review
                        @endif
                    @endif
                </span>
            </div>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Extracted Parties Panel -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Extracted Parties</h2>
                
                @if($extraction && $extraction->raw_json)
                    @php
                        $data = json_decode($extraction->raw_json, true);
                        $parties = collect($data['parties'] ?? [])->unique(function($party) {
                            return $party['name'] . '|' . ($party['address'] ?? '');
                        });
                    @endphp
                    
                    @forelse($parties as $index => $party)
                        <div class="border rounded-lg p-4 mb-4" id="party-{{ $index }}">
                            <div class="mb-3">
                                <h3 class="font-medium">{{ $party['name'] ?? 'Unknown' }}</h3>
                                @if(!empty($party['address']))
                                    <p class="text-sm text-gray-600">{{ $party['address'] }}</p>
                                @endif
                                @if(!empty($party['contact']))
                                    <p class="text-sm text-gray-500">{{ $party['contact'] }}</p>
                                @endif
                            </div>
                            
                            <!-- Role Assignment Radios -->
                            <div class="space-y-2">
                                <p class="text-sm font-medium text-gray-700">Assign Role:</p>
                                <div class="flex flex-wrap gap-4">
                                    @foreach(['customer', 'shipper', 'consignee', 'notify'] as $role)
                                        <label class="flex items-center">
                                            <input 
                                                type="radio" 
                                                name="party_{{ $index }}_role" 
                                                value="{{ $role }}"
                                                class="mr-2"
                                                hx-post="/intakes/{{ $intake->id }}/parties/assign"
                                                hx-vals='{"party_index": "{{ $index }}", "party_name": "{{ addslashes($party['name'] ?? '') }}", "party_address": "{{ addslashes($party['address'] ?? '') }}", "role": "{{ $role }}"}'
                                                hx-target="#assignment-status"
                                                hx-include="[name='_token']"
                                                @if(($intake->customer && $intake->customer->name === ($party['name'] ?? '')) && $role === 'customer') checked @endif
                                                @if(($intake->shipper && $intake->shipper->name === ($party['name'] ?? '')) && $role === 'shipper') checked @endif
                                                @if(($intake->consignee && $intake->consignee->name === ($party['name'] ?? '')) && $role === 'consignee') checked @endif
                                                @if(($intake->notify && $intake->notify->name === ($party['name'] ?? '')) && $role === 'notify') checked @endif
                                            >
                                            <span class="text-sm capitalize">{{ $role }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-gray-500 italic">No parties extracted from documents.</p>
                    @endforelse
                @else
                    <p class="text-gray-500 italic">No extraction data available.</p>
                @endif
                
                <!-- Assignment Status -->
                <div id="assignment-status" class="mt-4"></div>
            </div>

            <!-- JSON Data Viewer -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Raw Extraction Data</h2>
                
                @if($extraction && $extraction->raw_json)
                    <pre class="bg-gray-100 p-4 rounded-lg text-sm overflow-auto max-h-96">{{ json_encode(json_decode($extraction->raw_json), JSON_PRETTY_PRINT) }}</pre>
                @else
                    <p class="text-gray-500 italic">No extraction data available.</p>
                @endif
            </div>
        </div>

        <!-- Vehicles Section -->
        @if($intake->vehicles->count() > 0)
            <div class="mt-8 bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Extracted Vehicles</h2>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">VIN</th>
                                <th class="px-4 py-2 text-left">Make/Model</th>
                                <th class="px-4 py-2 text-left">Year</th>
                                <th class="px-4 py-2 text-left">Dimensions</th>
                                <th class="px-4 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($intake->vehicles as $vehicle)
                                <tr class="border-t">
                                    <td class="px-4 py-2 font-mono text-sm">{{ $vehicle->vin ?? $vehicle->license_plate }}</td>
                                    <td class="px-4 py-2">{{ $vehicle->make }} {{ $vehicle->model }}</td>
                                    <td class="px-4 py-2">{{ $vehicle->year }}</td>
                                    <td class="px-4 py-2 text-sm">
                                        @if($vehicle->length_m && $vehicle->width_m && $vehicle->height_m)
                                            {{ $vehicle->length_m }}×{{ $vehicle->width_m }}×{{ $vehicle->height_m }}m
                                            @if($vehicle->cbm)
                                                <br><span class="text-gray-500">{{ $vehicle->cbm }} CBM</span>
                                            @endif
                                        @else
                                            <span class="text-gray-400">No dimensions</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        @if($vehicle->spec_id && $vehicle->country_verified)
                                            <span class="text-green-600 text-sm">✓ Verified</span>
                                        @else
                                            <span class="text-yellow-600 text-sm">⚠ Needs verification</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- Actions -->
        <div class="mt-8 bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">Actions</h2>
            
            <div class="flex space-x-4">
                <button 
                    @if(!($statusInfo['all_verified'] ?? false)) disabled @endif
                    class="px-6 py-2 rounded-lg font-medium
                        @if($statusInfo['all_verified'] ?? false)
                            bg-green-600 hover:bg-green-700 text-white cursor-pointer
                        @else
                            bg-gray-300 text-gray-500 cursor-not-allowed
                        @endif"
                    hx-post="/intakes/{{ $intake->id }}/push-robaws"
                    hx-target="#push-status"
                    hx-include="[name='_token']"
                >
                    @if($statusInfo['all_verified'] ?? false)
                        Confirm & Push to Robaws
                    @else
                        Awaiting Verification
                    @endif
                </button>
                
                <a href="/intakes" class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-medium">
                    Back to Intakes
                </a>
            </div>
            
            <div id="push-status" class="mt-4"></div>
        </div>

        <!-- Hidden CSRF Token for HTMX -->
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
    </div>
</body>
</html>
