@extends('customer.layout')

@section('title', 'Request Quotation')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
            Request a Quotation
        </h1>
        <p class="text-gray-600">
            Fill in your shipping details and we'll suggest the best services with instant pricing.
        </p>
    </div>
    
    {{-- Livewire Component - Handles entire creation flow --}}
    @livewire('customer.quotation-creator', [
        'intakeId' => request()->get('intake_id')
    ])
    
</div>
@endsection
