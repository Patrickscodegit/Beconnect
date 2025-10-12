<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Shipping Schedules') - {{ config('app.name', 'Bconnect') }}</title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="@yield('meta_description', 'View real-time shipping schedules for RORO, FCL, LCL, and Break Bulk services. Find departure dates, carriers, and routes for your cargo.')">
    <meta name="keywords" content="shipping schedules, RORO schedules, FCL schedules, LCL schedules, cargo shipping, freight forwarding, Antwerp port, Belgium logistics">
    
    <!-- Open Graph -->
    <meta property="og:title" content="@yield('og_title', 'Shipping Schedules - Bconnect')">
    <meta property="og:description" content="@yield('og_description', 'Real-time shipping schedules for international cargo')">
    <meta property="og:type" content="website">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="font-sans antialiased bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <!-- Logo -->
                    <div class="flex-shrink-0 flex items-center">
                        <a href="{{ url('/') }}" class="text-xl font-bold text-gray-900">
                            {{ config('app.name', 'Bconnect') }}
                        </a>
                    </div>
                    
                    <!-- Navigation Links -->
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="{{ route('public.schedules.index') }}" 
                           class="{{ request()->routeIs('public.schedules.*') ? 'border-amber-500 text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }} inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Schedules
                        </a>
                    </div>
                </div>
                
                <!-- Right Side -->
                <div class="hidden sm:ml-6 sm:flex sm:items-center space-x-4">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="text-sm text-gray-700 hover:text-gray-900">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-gray-700 hover:text-gray-900">Sign in</a>
                    @endauth
                    
                    <a href="{{ route('public.schedules.index') }}#request-quote" 
                       class="inline-flex items-center px-4 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-500 focus:bg-amber-500 active:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Request Quote
                    </a>
                </div>
                
                <!-- Mobile menu button -->
                <div class="flex items-center sm:hidden">
                    <button type="button" 
                            x-data @click="$dispatch('toggle-mobile-menu')"
                            class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-amber-500">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile menu -->
        <div x-data="{ open: false }" 
             @toggle-mobile-menu.window="open = !open"
             x-show="open" 
             x-cloak
             class="sm:hidden">
            <div class="pt-2 pb-3 space-y-1">
                <a href="{{ route('public.schedules.index') }}" 
                   class="{{ request()->routeIs('public.schedules.*') ? 'bg-amber-50 border-amber-500 text-amber-700' : 'border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-800' }} block pl-3 pr-4 py-2 border-l-4 text-base font-medium">
                    Schedules
                </a>
            </div>
            <div class="pt-4 pb-3 border-t border-gray-200">
                <div class="space-y-1">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="block px-4 py-2 text-base font-medium text-gray-500 hover:text-gray-800 hover:bg-gray-100">Sign in</a>
                    @endauth
                    <a href="{{ route('public.schedules.index') }}#request-quote" class="block px-4 py-2 text-base font-medium text-amber-600 hover:text-amber-800 hover:bg-amber-50">Request Quote</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="text-center text-sm text-gray-500">
                &copy; {{ date('Y') }} {{ config('app.name', 'Bconnect') }}. All rights reserved.
            </div>
        </div>
    </footer>
</body>
</html>

