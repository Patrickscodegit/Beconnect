<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Request Quotation - Belgaco Logistics')</title>
    <meta name="description" content="@yield('description', 'Get a competitive quote for your shipping needs. Professional logistics services for RORO, FCL, LCL, and air freight.')">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Styles -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Livewire (includes Alpine.js) -->
    @livewireStyles

    <style>
        .form-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        .form-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="{{ url('/') }}" class="flex items-center">
                        <img src="{{ asset('images/belgaco-logo.png') }}" alt="Belgaco" class="h-8 w-auto" 
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                        <span class="hidden text-2xl font-bold text-gray-900">Belgaco</span>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="{{ url('/') }}" class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Home
                    </a>
                    <a href="{{ route('public.schedules.index') }}" class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                        Schedules
                    </a>
                    <a href="{{ route('public.quotations.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                        Request Quote
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white">
        <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <h3 class="text-lg font-semibold mb-4">Contact Info</h3>
                    <p class="text-gray-300 mb-2">
                        <i class="fas fa-phone mr-2"></i>
                        +32 3 123 45 67
                    </p>
                    <p class="text-gray-300 mb-2">
                        <i class="fas fa-envelope mr-2"></i>
                        info@belgaco.com
                    </p>
                    <p class="text-gray-300">
                        <i class="fas fa-map-marker-alt mr-2"></i>
                        Antwerp, Belgium
                    </p>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-4">Services</h3>
                    <ul class="space-y-2 text-gray-300">
                        <li><a href="#" class="hover:text-white">RORO Export/Import</a></li>
                        <li><a href="#" class="hover:text-white">FCL Services</a></li>
                        <li><a href="#" class="hover:text-white">LCL Consolidation</a></li>
                        <li><a href="#" class="hover:text-white">Air Freight</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
                    <ul class="space-y-2 text-gray-300">
                        <li><a href="{{ route('public.schedules.index') }}" class="hover:text-white">Shipping Schedules</a></li>
                        <li><a href="{{ route('public.quotations.create') }}" class="hover:text-white">Request Quote</a></li>
                        <li><a href="#" class="hover:text-white">Track Shipment</a></li>
                        <li><a href="#" class="hover:text-white">Contact Us</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-700 mt-8 pt-8 text-center text-gray-400">
                <p>&copy; {{ date('Y') }} Belgaco Logistics. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Livewire Scripts -->
    @livewireScripts
    
    @stack('scripts')
</body>
</html>
