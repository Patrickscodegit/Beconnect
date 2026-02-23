<x-guest-layout>
    <div class="max-w-xl">
        <h1 class="text-lg font-semibold text-gray-900">
            {{ __('Application received') }}
        </h1>
        <p class="mt-2 text-sm text-gray-600">
            {{ __('Thanks for your request. Our team will review it and email you when your account is ready.') }}
        </p>
        <div class="mt-6">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                {{ __('Back to login') }}
            </a>
        </div>
    </div>
</x-guest-layout>
