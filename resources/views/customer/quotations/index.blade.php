@extends('customer.layout')

@section('title', 'My Quotations')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    
    <!-- Header -->
    <div class="mb-8 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">My Quotations</h1>
            <p class="mt-2 text-gray-600">Track all your quotation requests and their status</p>
        </div>
        <a href="{{ route('customer.quotations.create') }}" 
           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 font-medium">
            <i class="fas fa-plus mr-2"></i>New Quotation
        </a>
    </div>

    <!-- Quotations List -->
    @if($quotations->count() > 0)
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Request #
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Route
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Service
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Status
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Total
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Date
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($quotations as $quotation)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-bold text-gray-900">{{ $quotation->request_number }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-gray-900">
                                    {{ $quotation->pol ?? 'N/A' }} → {{ $quotation->pod ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-gray-600">
                                    @php
                                        $serviceType = config('quotation.service_types.' . $quotation->service_type);
                                        $serviceName = is_array($serviceType) ? $serviceType['name'] : ($serviceType ?: $quotation->service_type);
                                    @endphp
                                    {{ $serviceName }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    @if($quotation->status === 'pending') bg-yellow-100 text-yellow-800
                                    @elseif($quotation->status === 'processing') bg-blue-100 text-blue-800
                                    @elseif($quotation->status === 'quoted') bg-green-100 text-green-800
                                    @elseif($quotation->status === 'accepted') bg-purple-100 text-purple-800
                                    @elseif($quotation->status === 'rejected') bg-red-100 text-red-800
                                    @else bg-gray-100 text-gray-800
                                    @endif">
                                    {{ ucfirst($quotation->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($quotation->total_incl_vat)
                                    <span class="text-sm font-semibold text-gray-900">
                                        €{{ number_format($quotation->total_incl_vat, 2) }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-400">Pending</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $quotation->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="{{ route('customer.quotations.show', $quotation) }}" 
                                   class="text-blue-600 hover:text-blue-900">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $quotations->links() }}
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <i class="fas fa-inbox text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">No Quotations Yet</h3>
            <p class="text-gray-600 mb-6">Start by requesting your first quotation</p>
            <a href="{{ route('customer.quotations.create') }}" 
               class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-md hover:bg-blue-700 font-medium">
                <i class="fas fa-plus mr-2"></i>Request Quotation
            </a>
        </div>
    @endif
</div>
@endsection

