@extends('public.quotations.layout')

@section('title', 'Track Quotation Request - Belgaco Logistics')

@section('content')
<div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">
                Track Your Quotation Request
            </h1>
            <p class="text-xl text-gray-600">
                Enter your request number to check the status of your quotation request.
            </p>
        </div>

        <!-- Status Form -->
        <div class="bg-white rounded-lg shadow-xl overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6">
                <h2 class="text-2xl font-bold text-white">
                    <i class="fas fa-search mr-2"></i>Request Status Lookup
                </h2>
            </div>
            
            <div class="p-8">
                @if(session('error'))
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Error</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    {{ session('error') }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <form action="{{ route('public.quotations.status') }}" method="GET">
                    <div class="mb-6">
                        <label for="request_number" class="block text-sm font-medium text-gray-700 mb-2">
                            Request Number <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="request_number" name="request_number" required
                               value="{{ request('request_number') }}"
                               class="form-input w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                               placeholder="e.g., QR-2025-0001"
                               pattern="QR-\d{4}-\d{4}"
                               title="Please enter a valid request number (QR-YYYY-NNNN)">
                        <p class="text-sm text-gray-500 mt-1">
                            Enter your request number in the format: QR-YYYY-NNNN
                        </p>
                    </div>
                    
                    <div class="flex justify-center">
                        <button type="submit" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg font-semibold text-lg shadow-lg hover:shadow-xl transition-all duration-300">
                            <i class="fas fa-search mr-2"></i>
                            Track Request
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Help Section -->
        <div class="mt-8 bg-white rounded-lg shadow-xl overflow-hidden">
            <div class="bg-gradient-to-r from-green-600 to-blue-600 p-6">
                <h3 class="text-xl font-bold text-white">
                    <i class="fas fa-question-circle mr-2"></i>Need Help?
                </h3>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-3">Can't Find Your Request Number?</h4>
                        <ul class="space-y-2 text-gray-600">
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                Check your email for the confirmation message
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                Look for an email from Belgaco Logistics
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                                Check your spam/junk folder
                            </li>
                        </ul>
                    </div>
                    
                    <div>
                        <h4 class="text-lg font-semibold text-gray-900 mb-3">Still Having Issues?</h4>
                        <div class="space-y-3">
                            <div class="flex items-center">
                                <i class="fas fa-phone text-blue-500 mr-3"></i>
                                <div>
                                    <p class="font-medium text-gray-900">Call Us</p>
                                    <p class="text-gray-600">+32 3 123 45 67</p>
                                </div>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-envelope text-blue-500 mr-3"></i>
                                <div>
                                    <p class="font-medium text-gray-900">Email Us</p>
                                    <p class="text-gray-600">info@belgaco.com</p>
                                </div>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-clock text-blue-500 mr-3"></i>
                                <div>
                                    <p class="font-medium text-gray-900">Business Hours</p>
                                    <p class="text-gray-600">Mon-Fri: 9:00-17:00 CET</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-8 text-center">
            <p class="text-gray-600 mb-4">Need to submit a new request?</p>
            <a href="{{ route('public.quotations.create') }}" 
               class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold transition-colors">
                <i class="fas fa-plus mr-2"></i>
                New Quotation Request
            </a>
        </div>
    </div>
</div>
@endsection
