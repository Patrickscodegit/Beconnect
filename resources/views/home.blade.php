<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bconnect - Your Partner in Shipping</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center">
                    <a href="{{ route('home') }}" class="text-2xl font-bold text-blue-900">
                        Bconnect
                    </a>
                    <span class="ml-3 text-sm text-gray-500 hidden sm:block">by Belgaco Shipping</span>
                </div>

                <!-- Navigation Links -->
                <div class="hidden md:flex items-center space-x-6">
                    <a href="{{ route('public.schedules.index') }}" class="text-gray-700 hover:text-blue-900 transition">
                        Schedules
                    </a>
                    <a href="{{ auth()->check() ? route('customer.quotations.create') : route('public.quotations.create') }}" class="text-gray-700 hover:text-blue-900 transition">
                        Request Quote
                    </a>
                    
                    @auth
                        @if(auth()->user()->email === 'patrick@belgaco.be' || (auth()->user()->is_admin ?? false))
                            <a href="{{ url('/admin') }}" class="text-gray-700 hover:text-blue-900 transition font-medium">
                                🛠️ Admin Panel
                            </a>
                        @endif
                        <a href="{{ route('customer.dashboard') }}" class="text-gray-700 hover:text-blue-900 transition">
                            Dashboard
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-700 hover:text-blue-900 transition">
                                Logout
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-700 hover:text-blue-900 transition">
                            Login
                        </a>
                        <a href="{{ route('register') }}" class="bg-blue-900 text-white px-4 py-2 rounded-lg hover:bg-blue-800 transition">
                            Register
                        </a>
                    @endauth
                </div>

                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button type="button" id="mobile-menu-button" class="text-gray-700 hover:text-blue-900">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile menu -->
        <div id="mobile-menu" class="hidden md:hidden border-t border-gray-200">
            <div class="px-4 py-3 space-y-3">
                <a href="{{ route('public.schedules.index') }}" class="block text-gray-700 hover:text-blue-900">Schedules</a>
                <a href="{{ auth()->check() ? route('customer.quotations.create') : route('public.quotations.create') }}" class="block text-gray-700 hover:text-blue-900">Request Quote</a>
                @auth
                    @if(auth()->user()->email === 'patrick@belgaco.be' || (auth()->user()->is_admin ?? false))
                        <a href="{{ url('/admin') }}" class="block text-gray-700 hover:text-blue-900 font-medium">🛠️ Admin Panel</a>
                    @endif
                    <a href="{{ route('customer.dashboard') }}" class="block text-gray-700 hover:text-blue-900">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full text-left text-gray-700 hover:text-blue-900">Logout</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="block text-gray-700 hover:text-blue-900">Login</a>
                    <a href="{{ route('register') }}" class="block bg-blue-900 text-white px-4 py-2 rounded-lg text-center">Register</a>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="bg-gradient-to-br from-blue-900 via-blue-800 to-blue-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-32">
            <div class="text-center">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold mb-6">
                    Your Partner in Shipping
                </h1>
                <p class="text-xl sm:text-2xl mb-4 text-blue-100">
                    Cost effective. Fast. Reliable.
                </p>
                <p class="text-lg mb-10 text-blue-200 max-w-2xl mx-auto">
                    Access shipping schedules, request quotations, and manage your logistics with ease.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="{{ route('public.schedules.index') }}" class="bg-white text-blue-900 px-8 py-4 rounded-lg font-semibold hover:bg-blue-50 transition shadow-lg">
                        View Schedules
                    </a>
                    <a href="{{ auth()->check() ? route('customer.quotations.create') : route('public.quotations.create') }}" class="bg-blue-700 text-white px-8 py-4 rounded-lg font-semibold hover:bg-blue-600 transition shadow-lg">
                        Request Quote
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-4">
                    Our Services
                </h2>
                <p class="text-lg text-gray-600">
                    Comprehensive shipping solutions for your business
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Ocean Freight -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-8 shadow-md hover:shadow-xl transition">
                    <div class="w-16 h-16 bg-blue-900 rounded-lg flex items-center justify-center mb-6">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-3">Ocean Freight</h3>
                    <p class="text-gray-600 mb-4">
                        RORO, FCL, LCL, and Conventional shipping to worldwide destinations.
                    </p>
                    <ul class="text-sm text-gray-600 space-y-2">
                        <li>• RORO Shipping</li>
                        <li>• FCL (Full Container Load)</li>
                        <li>• LCL (Less than Container Load)</li>
                        <li>• Breakbulk</li>
                    </ul>
                </div>

                <!-- Schedules & Tracking -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-8 shadow-md hover:shadow-xl transition">
                    <div class="w-16 h-16 bg-green-700 rounded-lg flex items-center justify-center mb-6">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-3">Schedules & Tracking</h3>
                    <p class="text-gray-600 mb-4">
                        Real-time vessel schedules and shipment tracking from multiple carriers.
                    </p>
                    <a href="{{ route('public.schedules.index') }}" class="text-green-700 font-semibold hover:text-green-800 transition inline-flex items-center">
                        View Schedules
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>

                <!-- Quotations -->
                <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-8 shadow-md hover:shadow-xl transition">
                    <div class="w-16 h-16 bg-orange-600 rounded-lg flex items-center justify-center mb-6">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-3">Request Quotations</h3>
                    <p class="text-gray-600 mb-4">
                        Get competitive quotes for your shipping needs quickly and efficiently.
                    </p>
                    <a href="{{ auth()->check() ? route('customer.quotations.create') : route('public.quotations.create') }}" class="text-orange-600 font-semibold hover:text-orange-700 transition inline-flex items-center">
                        Request Quote
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-16 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-4">
                    Why Choose Bconnect?
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-6 h-6 text-blue-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Cost Effective</h3>
                    <p class="text-sm text-gray-600">Competitive rates for all shipping services</p>
                </div>

                <div class="text-center">
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-6 h-6 text-blue-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Fast Service</h3>
                    <p class="text-sm text-gray-600">Quick turnaround times and efficient processing</p>
                </div>

                <div class="text-center">
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-6 h-6 text-blue-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Reliable</h3>
                    <p class="text-sm text-gray-600">Trusted by businesses worldwide</p>
                </div>

                <div class="text-center">
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-6 h-6 text-blue-900" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                        </svg>
                    </div>
                    <h3 class="font-semibold text-gray-900 mb-2">Global Network</h3>
                    <p class="text-sm text-gray-600">Worldwide shipping destinations</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-16 bg-blue-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl font-bold mb-6">
                Ready to Get Started?
            </h2>
            <p class="text-xl mb-8 text-blue-100">
                Create an account to access the customer portal and manage your shipments
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                @guest
                    <a href="{{ route('register') }}" class="bg-white text-blue-900 px-8 py-4 rounded-lg font-semibold hover:bg-blue-50 transition shadow-lg">
                        Create Account
                    </a>
                    <a href="{{ route('login') }}" class="bg-blue-700 text-white px-8 py-4 rounded-lg font-semibold hover:bg-blue-600 transition shadow-lg border-2 border-blue-600">
                        Login
                    </a>
                @else
                    <a href="{{ route('customer.dashboard') }}" class="bg-white text-blue-900 px-8 py-4 rounded-lg font-semibold hover:bg-blue-50 transition shadow-lg">
                        Go to Dashboard
                    </a>
                @endguest
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-300 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <h3 class="text-white text-lg font-semibold mb-4">Bconnect</h3>
                    <p class="text-sm text-gray-400">
                        Your partner in shipping. Part of Belgaco Shipping Bvba, Antwerp, Belgium.
                    </p>
                </div>

                <div>
                    <h3 class="text-white text-lg font-semibold mb-4">Quick Links</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="{{ route('public.schedules.index') }}" class="hover:text-white transition">Schedules</a></li>
                        <li><a href="{{ auth()->check() ? route('customer.quotations.create') : route('public.quotations.create') }}" class="hover:text-white transition">Request Quote</a></li>
                        @auth
                            <li><a href="{{ route('customer.dashboard') }}" class="hover:text-white transition">Dashboard</a></li>
                        @else
                            <li><a href="{{ route('login') }}" class="hover:text-white transition">Login</a></li>
                            <li><a href="{{ route('register') }}" class="hover:text-white transition">Register</a></li>
                        @endauth
                    </ul>
                </div>

                <div>
                    <h3 class="text-white text-lg font-semibold mb-4">Contact</h3>
                    <ul class="space-y-2 text-sm">
                        <li>Belgaco Shipping Bvba</li>
                        <li>Antwerp, Belgium</li>
                        <li class="pt-2">
                            <a href="https://www.belgaco-shipping.com" target="_blank" class="text-blue-400 hover:text-blue-300 transition">
                                www.belgaco-shipping.com
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-gray-800 mt-8 pt-8 text-center text-sm text-gray-400">
                <p>&copy; {{ date('Y') }} Belgaco Shipping Bvba. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Mobile Menu Script -->
    <script>
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const menu = document.getElementById('mobile-menu');
            menu.classList.toggle('hidden');
        });
    </script>
</body>
</html>

