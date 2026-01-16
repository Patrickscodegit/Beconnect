@extends('customer.layout')

@section('title', 'Quotation ' . $quotationRequest->request_number)

@section('content')
@php
    // Calculate total from articles for display (used in summary card and pricing section)
    $calculatedTotal = null;
    if ($quotationRequest->status === 'quoted' && $quotationRequest->total_incl_vat) {
        $calculatedTotal = $quotationRequest->total_incl_vat;
    } else {
        $calculatedSubtotal = 0;
        foreach($quotationRequest->articles as $article) {
            $articleModel = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
                ->where('article_cache_id', $article->id)
                ->first();
            $subtotal = $article->pivot->subtotal ?? 0;
            if ($articleModel && $articleModel->subtotal) {
                $subtotal = $articleModel->subtotal;
            } elseif ($article->pivot->unit_price) {
                $displayQty = $articleModel ? $articleModel->display_quantity : ($article->pivot->quantity ?? 1);
                $subtotal = $displayQty * ($article->pivot->unit_price ?? 0);
            }
            $calculatedSubtotal += $subtotal;
        }
        if ($calculatedSubtotal > 0) {
            $calculatedTotal = $calculatedSubtotal;
        }
    }
@endphp
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    
    <!-- Print Header (only visible when printing) -->
    <div class="print-header-info">
        <h1>Quotation {{ $quotationRequest->request_number }}</h1>
        <p>Date: {{ $quotationRequest->created_at->format('F j, Y') }}</p>
        <p>Route: {{ $quotationRequest->pol }} → {{ $quotationRequest->pod }}</p>
    </div>
    
    <!-- Header -->
    <div class="mb-8">
        <div class="flex justify-between items-start">
            <div>
                <div class="flex items-center mb-2">
                    <h1 class="text-3xl font-bold text-gray-900">{{ $quotationRequest->request_number }}</h1>
                    <span class="ml-4 px-3 py-1 rounded-full text-sm font-semibold
                        @if($quotationRequest->status === 'pending') bg-yellow-100 text-yellow-800
                        @elseif($quotationRequest->status === 'processing') bg-blue-100 text-blue-800
                        @elseif($quotationRequest->status === 'quoted') bg-green-100 text-green-800
                        @elseif($quotationRequest->status === 'accepted') bg-purple-100 text-purple-800
                        @elseif($quotationRequest->status === 'rejected') bg-red-100 text-red-800
                        @else bg-gray-100 text-gray-800
                        @endif">
                        {{ ucfirst($quotationRequest->status) }}
                    </span>
                </div>
                <p class="text-gray-600">Requested on {{ $quotationRequest->created_at->format('F j, Y') }}</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" 
                        class="no-print inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition font-medium">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <a href="{{ route('customer.quotations.index') }}" 
                   class="no-print text-gray-600 hover:text-gray-900 font-medium">
                    <i class="fas fa-arrow-left mr-2"></i>Back to List
                </a>
            </div>
        </div>
    </div>

    <!-- Summary Card -->
    <div class="print-section print-summary mb-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg shadow p-6 border border-blue-200">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <p class="text-sm font-medium text-gray-600 mb-1">Route</p>
                <p class="text-lg font-semibold text-gray-900">
                    {{ $quotationRequest->pol }} → {{ $quotationRequest->pod }}
                </p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-600 mb-1">Service Type</p>
                <p class="text-lg font-semibold text-gray-900">
                    @php
                        $serviceType = config('quotation.service_types.' . $quotationRequest->service_type);
                        $serviceName = is_array($serviceType) ? $serviceType['name'] : ($serviceType ?: $quotationRequest->service_type);
                    @endphp
                    {{ $serviceName }}
                </p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-600 mb-1">Total</p>
                <p class="text-2xl font-bold text-blue-600">
                    @if($calculatedTotal !== null)
                        €{{ number_format($calculatedTotal, 2) }}
                    @else
                        <span class="text-gray-400">Pending</span>
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- Edit/Duplicate Actions Banner --}}
    @if($canEdit)
        <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    <p class="text-sm text-blue-800">
                        You can edit this quotation until it's approved by our team.
                    </p>
                </div>
                <a href="{{ route('customer.quotations.create', ['edit' => $quotationRequest->id]) }}" 
                   class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                    <i class="fas fa-edit mr-2"></i>Edit Quotation
                </a>
            </div>
        </div>
    @elseif(in_array($quotationRequest->status, ['quoted', 'accepted', 'approved']))
        <div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    <p class="text-sm text-green-800">
                        This quotation has been approved. You can duplicate it to create a new quotation with the same details.
                    </p>
                </div>
                <form action="{{ route('customer.quotations.duplicate', $quotationRequest) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" 
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium">
                        <i class="fas fa-copy mr-2"></i>Duplicate
                    </button>
                </form>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Route & Service -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <i class="fas fa-route mr-2"></i>Route & Service Information
                </h2>
                <dl class="grid grid-cols-2 gap-4">
                    @if($quotationRequest->por)
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Place of Receipt (POR)</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $quotationRequest->por }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Port of Loading (POL)</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-semibold">{{ $quotationRequest->pol }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Port of Discharge (POD)</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-semibold">{{ $quotationRequest->pod }}</dd>
                    </div>
                    @if($quotationRequest->fdest)
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Final Destination (FDEST)</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $quotationRequest->fdest }}</dd>
                        </div>
                    @endif
                    @if($quotationRequest->in_transit_to)
                        <div>
                            <dt class="text-sm font-medium text-gray-500">In Transit To</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $quotationRequest->in_transit_to }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Service Type</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @php
                                $serviceType = config('quotation.service_types.' . $quotationRequest->service_type);
                                $serviceName = is_array($serviceType) ? $serviceType['name'] : ($serviceType ?: $quotationRequest->service_type);
                            @endphp
                            {{ $serviceName }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Trade Direction</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ ucfirst($quotationRequest->trade_direction) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Cargo Information -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <i class="fas fa-box mr-2"></i>Cargo Information
                </h2>
                
                @if($quotationRequest->commodityItems && $quotationRequest->commodityItems->count() > 0)
                    {{-- Detailed Quote Mode: Show Commodity Items --}}
                    <div class="space-y-4">
                        @foreach($quotationRequest->commodityItems as $item)
                            <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Item #{{ $item->line_number }}
                                        @if($item->commodity_type)
                                            <span class="ml-2 text-xs font-normal text-gray-600">({{ ucwords(str_replace('_', ' ', $item->commodity_type)) }})</span>
                                        @endif
                                    </h3>
                                    @if($item->quantity > 1)
                                        <span class="text-xs text-gray-500">Qty: {{ $item->quantity }}</span>
                                    @endif
                                </div>
                                
                                <dl class="grid grid-cols-2 gap-3 text-sm">
                                    @if($item->category)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Category</dt>
                                            <dd class="mt-1 text-gray-900">{{ ucwords(str_replace('_', ' ', $item->category)) }}</dd>
                                        </div>
                                    @endif
                                    @if($item->make)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Make</dt>
                                            <dd class="mt-1 text-gray-900">{{ $item->make }}</dd>
                                        </div>
                                    @endif
                                    @if($item->type_model)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Type/Model</dt>
                                            <dd class="mt-1 text-gray-900">{{ $item->type_model }}</dd>
                                        </div>
                                    @endif
                                    @if($item->year)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Year</dt>
                                            <dd class="mt-1 text-gray-900">{{ $item->year }}</dd>
                                        </div>
                                    @endif
                                    @if($item->condition)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Condition</dt>
                                            <dd class="mt-1 text-gray-900">{{ ucfirst($item->condition) }}</dd>
                                        </div>
                                    @endif
                                    @if($item->length_cm || $item->width_cm || $item->height_cm)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Dimensions</dt>
                                            <dd class="mt-1 text-gray-900">
                                                @if($item->length_cm && $item->width_cm && $item->height_cm)
                                                    {{ number_format($item->length_cm, 0) }} × {{ number_format($item->width_cm, 0) }} × {{ number_format($item->height_cm, 0) }} cm
                                                @else
                                                    {{ $item->length_cm ? number_format($item->length_cm, 0) . ' cm' : '' }}
                                                    {{ $item->width_cm ? number_format($item->width_cm, 0) . ' cm' : '' }}
                                                    {{ $item->height_cm ? number_format($item->height_cm, 0) . ' cm' : '' }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endif
                                    @if($item->weight_kg)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">Weight</dt>
                                            <dd class="mt-1 text-gray-900">{{ number_format($item->weight_kg, 2) }} kg</dd>
                                        </div>
                                    @endif
                                    @if($item->cbm)
                                        <div>
                                            <dt class="text-xs font-medium text-gray-500">CBM</dt>
                                            <dd class="mt-1 text-gray-900">{{ number_format($item->cbm, 4) }} m³</dd>
                                        </div>
                                    @endif
                                </dl>
                                
                                @if($item->extra_info)
                                    <div class="mt-3 pt-3 border-t border-gray-200">
                                        <dt class="text-xs font-medium text-gray-500 mb-1">Additional Info</dt>
                                        <dd class="text-sm text-gray-900">{{ $item->extra_info }}</dd>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-box-open text-4xl mb-2"></i>
                        <p>No commodity items added yet.</p>
                    </div>
                @endif
                
                @if($quotationRequest->special_requirements)
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <dt class="text-sm font-medium text-gray-500 mb-2">Special Requirements</dt>
                        <dd class="text-sm text-gray-900">{{ $quotationRequest->special_requirements }}</dd>
                    </div>
                @endif
            </div>

            {{-- Selected Articles --}}
            @if($quotationRequest->articles->count() > 0)
                <div class="print-section print-services bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-check-square mr-2 text-green-600"></i>Selected Services
                    </h2>
                    
                    <div class="space-y-4">
                        @foreach($quotationRequest->articles as $article)
                            @php
                                // Get the QuotationRequestArticle model to access methods
                                $articleModel = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotationRequest->id)
                                    ->where('article_cache_id', $article->id)
                                    ->first();
                                $isLmArticle = strtoupper(trim($article->pivot->unit_type ?? $article->unit_type ?? '')) === 'LM';
                                $lmBreakdown = $articleModel ? $articleModel->getLmCalculationBreakdown() : null;
                                $unitType = strtoupper(trim($article->pivot->unit_type ?? $article->unit_type ?? 'UNIT'));
                                $displayQty = $articleModel ? $articleModel->display_quantity : ($article->pivot->quantity ?? 1);
                                $unitPrice = $article->pivot->unit_price ?? $article->unit_price ?? 0;
                                $subtotal = $article->pivot->subtotal ?? ($displayQty * $unitPrice);
                                // Primary display: sales_name > article_name > description
                                $primaryName = $article->sales_name ?? $article->article_name ?? $article->description ?? 'N/A';
                                // Secondary description if different from primary
                                $secondaryDescription = null;
                                if ($article->description && $article->description !== $primaryName && $article->description !== '-') {
                                    $secondaryDescription = $article->description;
                                } elseif ($article->article_name && $article->article_name !== $primaryName && !$article->sales_name) {
                                    $secondaryDescription = $article->article_name;
                                }
                            @endphp
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 hover:border-blue-300 transition">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <p class="font-semibold text-gray-900 text-base">{{ $primaryName }}</p>
                                            @if($unitType && $unitType !== 'UNIT')
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs font-medium rounded">
                                                    {{ $unitType }}
                                                </span>
                                            @endif
                                            @if($article->commodity_type)
                                                <span class="px-2 py-0.5 bg-indigo-100 text-indigo-800 text-xs font-medium rounded">
                                                    {{ strtoupper($article->commodity_type) }}
                                                </span>
                                            @endif
                                        </div>
                                        
                                        @if($secondaryDescription)
                                            <p class="text-sm text-gray-600 mb-2 italic">{{ $secondaryDescription }}</p>
                                        @endif
                                        
                                        <p class="text-sm text-gray-600 mb-2">
                                            <span class="font-medium">Code:</span> {{ $article->article_code }}
                                        </p>
                                        
                                        @if($isLmArticle && $lmBreakdown)
                                            <div class="mt-3 p-3 bg-white rounded border border-gray-200">
                                                <p class="text-sm text-gray-700 font-medium mb-1">LM Calculation:</p>
                                                <p class="text-sm text-gray-700">
                                                    {{ number_format($lmBreakdown['lm_per_item'], 2) }} LM × 
                                                    {{ $lmBreakdown['quantity'] }} qty = 
                                                    <span class="font-semibold">{{ number_format($lmBreakdown['total_lm'], 2) }} LM</span> × 
                                                    €{{ number_format($lmBreakdown['price'], 2) }} = 
                                                    <span class="font-semibold text-blue-600">€{{ number_format($lmBreakdown['subtotal'], 2) }}</span>
                                                </p>
                                            </div>
                                        @elseif(auth()->user()->pricing_tier_id || $quotationRequest->pricing_tier_id || $quotationRequest->status === 'quoted')
                                            <div class="mt-2">
                                                <p class="text-sm text-gray-700">
                                                    <span class="font-medium">Qty:</span> {{ number_format($displayQty, 2) }} × 
                                                    <span class="font-medium">Price:</span> €{{ number_format($unitPrice, 2) }} = 
                                                    <span class="font-semibold text-blue-600 text-base">
                                                        €{{ number_format($subtotal, 2) }}
                                                    </span>
                                                </p>
                                            </div>
                                        @endif
                                        
                                        @if(!empty($article->pivot?->notes))
                                            <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 whitespace-pre-line">
                                                <i class="fas fa-info-circle mr-1"></i>{{ $article->pivot->notes }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Pricing -->
            <div class="print-section print-pricing bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <i class="fas fa-euro-sign mr-2"></i>Pricing
                </h2>
                @if($quotationRequest->status === 'quoted' && $quotationRequest->total_incl_vat)
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Subtotal</dt>
                            <dd class="text-sm text-gray-900">€{{ number_format($quotationRequest->subtotal, 2) }}</dd>
                        </div>
                        @if($quotationRequest->discount_amount > 0)
                            <div class="flex justify-between">
                                <dt class="text-sm font-medium text-gray-500">Discount ({{ $quotationRequest->discount_percentage }}%)</dt>
                                <dd class="text-sm text-green-600">-€{{ number_format($quotationRequest->discount_amount, 2) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Total (excl. VAT)</dt>
                            <dd class="text-sm text-gray-900">€{{ number_format($quotationRequest->total_excl_vat, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">{{ $quotationRequest->vat_label }}</dt>
                            <dd class="text-sm text-gray-900">€{{ number_format($quotationRequest->vat_amount, 2) }}</dd>
                        </div>
                        <div class="flex justify-between pt-3 border-t border-gray-200">
                            <dt class="text-lg font-bold text-gray-900">{{ $quotationRequest->total_label }}</dt>
                            <dd class="text-lg font-bold text-gray-900">€{{ number_format($quotationRequest->total_incl_vat, 2) }}</dd>
                        </div>
                    </dl>
                    
                    @if($quotationRequest->expires_at)
                        <p class="mt-4 text-sm text-gray-500">
                            <i class="fas fa-clock mr-1"></i>
                            Valid until {{ $quotationRequest->expires_at->format('F j, Y') }}
                        </p>
                    @endif
                @else
                    @if($calculatedTotal !== null)
                        <dl class="space-y-3">
                            <div class="flex justify-between">
                                <dt class="text-sm font-medium text-gray-500">Subtotal</dt>
                                <dd class="text-sm text-gray-900">€{{ number_format($calculatedTotal, 2) }}</dd>
                            </div>
                            <div class="flex justify-between pt-3 border-t border-gray-200">
                                <dt class="text-lg font-bold text-gray-900">Total</dt>
                                <dd class="text-lg font-bold text-gray-900">€{{ number_format($calculatedTotal, 2) }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <p class="text-sm text-amber-800">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Note:</strong> This pricing is subject to review and confirmation by the Bconnect team.
                            </p>
                        </div>
                    @else
                        <div class="text-center py-6 text-gray-500">
                            <i class="fas fa-hourglass-half text-3xl mb-2"></i>
                            <p class="text-sm">Pricing will be available once the quotation is processed.</p>
                        </div>
                    @endif
                @endif
            </div>

            @php
                $carrierClauses = collect($quotationRequest->carrier_clauses ?? []);
            @endphp
            @if($carrierClauses->isNotEmpty())
                <div class="print-section print-clauses bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-file-contract mr-2 text-blue-600"></i>Carrier Clauses
                    </h2>

                    <div class="space-y-4">
                        @foreach($carrierClauses->groupBy('clause_type') as $type => $clauses)
                            <div>
                                <h3 class="text-sm font-semibold text-gray-700 mb-2">{{ ucfirst(strtolower($type)) }}</h3>
                                <ul class="list-disc pl-5 space-y-1 text-sm text-gray-700">
                                    @foreach($clauses as $clause)
                                        <li>{{ strip_tags($clause['text'] ?? '') }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Uploaded Files -->
            @if($quotationRequest->files->count() > 0)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-paperclip mr-2"></i>Uploaded Files
                    </h2>
                    <ul class="space-y-2">
                        @foreach($quotationRequest->files as $file)
                            <li class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div class="flex items-center">
                                    <i class="fas fa-file text-gray-400 mr-3"></i>
                                    <span class="text-sm text-gray-900">{{ $file->original_filename }}</span>
                                    <span class="text-xs text-gray-500 ml-2">({{ number_format($file->file_size / 1024, 2) }} KB)</span>
                                </div>
                                <a href="{{ Storage::disk('public')->url($file->file_path) }}" 
                                   target="_blank"
                                   class="text-blue-600 hover:text-blue-800 text-sm">
                                    <i class="fas fa-download"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="print-section print-conditions bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">
                    <i class="fas fa-file-alt mr-2 text-gray-600"></i>General Conditions
                </h2>
                <div class="space-y-3 text-sm text-gray-700">
                    <p>All services and operations performed by Belgaco BV are subject to the terms outlined in the most recent version of the following conditions, as applicable:</p>
                    <p>
                        <strong>Maritime services:</strong> The General Conditions of the Belgian Forwarders for all our maritime related services. Full details can be found at:
                        <a href="https://www.belgaco-shipping.com/terms-and-conditions" class="text-blue-600 hover:text-blue-800" target="_blank" rel="noopener">https://www.belgaco-shipping.com/terms-and-conditions</a>
                    </p>
                    <p>
                        <strong>Road transport services:</strong> The Convention on the Contract for the International Carriage of Goods by Road (CMR), governing international road transport agreements.
                    </p>
                    <p>
                        <strong>Standard conditions:</strong> Belgian Freight Forwarders - Standard Trading Conditions (Free translation)-11.pdf
                    </p>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            
            <!-- Status Timeline -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Status Timeline</h3>
                <div class="space-y-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center">
                                <i class="fas fa-check text-white text-sm"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Submitted</p>
                            <p class="text-xs text-gray-500">{{ $quotationRequest->created_at->format('M d, Y H:i') }}</p>
                        </div>
                    </div>
                    
                    @if(in_array($quotationRequest->status, ['processing', 'quoted', 'accepted', 'rejected']))
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center">
                                    <i class="fas fa-cog text-white text-sm"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Processing</p>
                                <p class="text-xs text-gray-500">Being reviewed by our team</p>
                            </div>
                        </div>
                    @endif
                    
                    @if(in_array($quotationRequest->status, ['quoted', 'accepted', 'rejected']))
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center">
                                    <i class="fas fa-file-invoice text-white text-sm"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Quoted</p>
                                <p class="text-xs text-gray-500">{{ $quotationRequest->quoted_at?->format('M d, Y H:i') }}</p>
                            </div>
                        </div>
                    @endif
                    
                    @if($quotationRequest->status === 'accepted')
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-8 w-8 rounded-full bg-green-600 flex items-center justify-center">
                                    <i class="fas fa-check-circle text-white text-sm"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Accepted</p>
                                <p class="text-xs text-gray-500">Quotation accepted</p>
                            </div>
                        </div>
                    @endif
                    
                    @if($quotationRequest->status === 'rejected')
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-8 w-8 rounded-full bg-red-500 flex items-center justify-center">
                                    <i class="fas fa-times-circle text-white text-sm"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-900">Rejected</p>
                                <p class="text-xs text-gray-500">Quotation declined</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Actions (if quoted) -->
            @if($quotationRequest->status === 'quoted')
                <div class="bg-green-50 rounded-lg border border-green-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Action Required</h3>
                    <p class="text-sm text-gray-600 mb-4">This quotation is waiting for your response.</p>
                    <div class="space-y-3">
                        <button class="w-full px-4 py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition-colors">
                            <i class="fas fa-check mr-2"></i>Accept Quotation
                        </button>
                        <button class="w-full px-4 py-3 bg-white text-gray-700 border border-gray-300 rounded-lg font-semibold hover:bg-gray-50 transition-colors">
                            <i class="fas fa-times mr-2"></i>Decline
                        </button>
                    </div>
                </div>
            @endif

            <!-- Contact Support -->
            <div class="bg-blue-50 rounded-lg border border-blue-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Need Help?</h3>
                <p class="text-sm text-gray-600 mb-4">Have questions about this quotation?</p>
                <a href="mailto:info@belgaco.com?subject=Question about {{ $quotationRequest->request_number }}" 
                   class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    <i class="fas fa-envelope mr-2"></i>Contact Support
                </a>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    @media print {
        /* Hide non-essential elements */
        .no-print,
        nav,
        header,
        footer,
        .sidebar,
        button:not(.print-only),
        a:not(.print-only),
        .bg-blue-50,
        .bg-green-50,
        .bg-yellow-50 {
            display: none !important;
        }
        
        /* Print-specific layout */
        body {
            background: white;
            color: black;
            font-size: 10.5pt;
            line-height: 1.35;
        }
        
        .max-w-7xl {
            max-width: 100%;
            padding: 0;
            width: 100%;
        }

        /* Use full width in print layout */
        .grid {
            grid-template-columns: 1fr !important;
        }

        .lg\:col-span-2,
        .lg\:col-span-3,
        .col-span-2,
        .col-span-3 {
            grid-column: 1 / -1 !important;
        }
        
        /* Ensure proper page breaks */
        .bg-white {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Print header and footer */
        @page {
            margin: 1.2cm;
            @top-center {
                content: "Quotation {{ $quotationRequest->request_number }}";
                font-size: 9pt;
                color: #666;
            }
            @bottom-center {
                content: "Page " counter(page) " of " counter(pages) " | Printed on {{ date('F j, Y') }}";
                font-size: 8.5pt;
                color: #666;
            }
        }
        
        /* Improve readability */
        h1, h2, h3 {
            color: #000;
            page-break-after: avoid;
        }

        h2 {
            font-size: 13pt;
            margin-bottom: 6pt;
        }

        h3 {
            font-size: 11pt;
            margin-bottom: 4pt;
        }
        
        .bg-gray-50,
        .bg-white {
            background: white !important;
            border: 1px solid #ddd !important;
        }
        
        /* Ensure colors print */
        .text-blue-600,
        .text-green-600,
        .text-red-600 {
            color: #000 !important;
        }
        
        /* Remove shadows and rounded corners for print */
        .rounded-lg,
        .rounded {
            border-radius: 0 !important;
        }
        
        .shadow {
            box-shadow: none !important;
        }
        
        /* Summary card styling for print */
        .bg-gradient-to-r {
            background: #f0f0f0 !important;
            border: 2px solid #000 !important;
        }
        
        /* Ensure tables and lists print well */
        table, ul, ol {
            page-break-inside: avoid;
        }

        /* Print section spacing and grouping */
        .print-section {
            margin-bottom: 12pt;
            padding: 10pt !important;
        }

        .print-summary {
            margin-bottom: 10pt;
        }

        .print-services,
        .print-pricing,
        .print-clauses,
        .print-conditions {
            page-break-inside: avoid;
        }

        .print-clauses {
            font-size: 9.2pt;
            line-height: 1.25;
            page-break-before: always;
        }

        .print-clauses .space-y-4 {
            gap: 4pt !important;
        }

        .print-clauses h3 {
            font-size: 10pt;
            margin-bottom: 3pt;
        }

        .print-clauses ul {
            list-style-position: outside;
            padding-left: 14pt;
            margin-top: 3pt;
            margin-bottom: 4pt;
            break-inside: auto;
            page-break-inside: auto;
        }

        .print-clauses li {
            margin-bottom: 1pt;
        }

        .print-conditions {
            page-break-before: always;
        }
        
        /* Print header info at top of first page */
        .print-header-info {
            display: block !important;
            text-align: center;
            margin-bottom: 12pt;
            padding-bottom: 6pt;
            border-bottom: 2px solid #000;
        }
        
        .print-header-info h1 {
            margin: 0;
            font-size: 16pt;
        }
        
        .print-header-info p {
            margin: 3pt 0;
            font-size: 9pt;
        }
    }
    
    @media screen {
        .print-header-info {
            display: none;
        }
    }
</style>
@endpush
@endsection

