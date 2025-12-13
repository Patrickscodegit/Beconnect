@extends('customer.layout')

@section('title', 'Quotation ' . $quotationRequest->request_number)

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    
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
            <a href="{{ route('customer.quotations.index') }}" 
               class="text-gray-600 hover:text-gray-900 font-medium">
                <i class="fas fa-arrow-left mr-2"></i>Back to List
            </a>
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
                    {{-- Quick Quote Mode: Show Simple Description --}}
                    <dl class="space-y-3">
                        @if($quotationRequest->commodity_type)
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Commodity Type</dt>
                                <dd class="mt-1 text-sm text-gray-900 font-semibold">{{ ucfirst(str_replace('_', ' ', $quotationRequest->commodity_type)) }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Description</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $quotationRequest->cargo_description ?: 'No description provided' }}</dd>
                        </div>
                        @if($quotationRequest->cargo_weight || $quotationRequest->cargo_volume || $quotationRequest->cargo_dimensions)
                            <div class="grid grid-cols-3 gap-3 pt-3 border-t border-gray-200">
                                @if($quotationRequest->cargo_weight)
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Weight</dt>
                                        <dd class="mt-1 text-sm text-gray-900">{{ number_format($quotationRequest->cargo_weight, 2) }} kg</dd>
                                    </div>
                                @endif
                                @if($quotationRequest->cargo_volume)
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Volume</dt>
                                        <dd class="mt-1 text-sm text-gray-900">{{ number_format($quotationRequest->cargo_volume, 2) }} m³</dd>
                                    </div>
                                @endif
                                @if($quotationRequest->cargo_dimensions)
                                    <div>
                                        <dt class="text-xs font-medium text-gray-500">Dimensions</dt>
                                        <dd class="mt-1 text-sm text-gray-900">{{ $quotationRequest->cargo_dimensions }}</dd>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </dl>
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
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-check-square mr-2 text-green-600"></i>Selected Services
                    </h2>
                    
                    <div class="space-y-3">
                        @foreach($quotationRequest->articles as $article)
                            <div class="flex items-start justify-between bg-gray-50 p-4 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <p class="font-medium text-gray-900">{{ $article->description ?: $article->article_name }}</p>
                                    <p class="text-sm text-gray-600 mt-1">
                                        Code: {{ $article->article_code }}
                                    </p>
                                    @if(auth()->user()->pricing_tier_id || $quotationRequest->pricing_tier_id)
                                        <p class="text-sm text-gray-700 mt-2">
                                            Qty: {{ $article->pivot->quantity ?? 1 }} × 
                                            €{{ number_format($article->pivot->unit_price ?? $article->unit_price, 2) }} = 
                                            <span class="font-semibold text-blue-600">
                                                €{{ number_format(($article->pivot->quantity ?? 1) * ($article->pivot->unit_price ?? $article->unit_price), 2) }}
                                            </span>
                                        </p>
                                    @endif
                                </div>
                                @if($article->commodity_type)
                                    <span class="ml-4 px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded">
                                        {{ $article->commodity_type }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Pricing (if quoted) -->
            @if($quotationRequest->status === 'quoted' && $quotationRequest->total_incl_vat)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">
                        <i class="fas fa-euro-sign mr-2"></i>Pricing
                    </h2>
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
@endsection

