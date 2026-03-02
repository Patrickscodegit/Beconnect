@extends('customer.layout')

@section('title', 'My Profile')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

    <!-- Back link -->
    <div class="mb-6">
        <a href="{{ route('customer.dashboard') }}" class="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 font-medium">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>

    <h1 class="text-2xl font-bold text-gray-900 mb-8">My Profile</h1>

    {{-- ── Company Information ─────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-5">
            <div>
                <h2 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-building mr-2 text-blue-500"></i>Company Information
                </h2>
                <p class="mt-1 text-sm text-gray-500">Managed in Robaws CRM — contact your account manager to make changes.</p>
            </div>
            @if($robawsCache)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i>Linked
                </span>
            @else
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                    <i class="fas fa-exclamation-circle mr-1"></i>Not linked
                </span>
            @endif
        </div>

        @if($robawsCache)
            {{-- Name + badges --}}
            <div class="mb-5 pb-5 border-b border-gray-100">
                <p class="text-xl font-semibold text-gray-900">{{ $robawsCache->name }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @if($robawsCache->client_type)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 capitalize">
                            {{ $robawsCache->client_type }}
                        </span>
                    @endif
                    @if($robawsCache->role)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                            {{ $robawsCache->role }}
                        </span>
                    @endif
                    @if($robawsCache->currency)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                            <i class="fas fa-coins mr-1"></i>{{ $robawsCache->currency }}
                        </span>
                    @endif
                    @if($robawsCache->language)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                            <i class="fas fa-globe mr-1"></i>{{ strtoupper($robawsCache->language) }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Contact details --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5 pb-5 border-b border-gray-100">
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Email</p>
                    <p class="text-sm text-gray-800">
                        @if($robawsCache->email)
                            <a href="mailto:{{ $robawsCache->email }}" class="text-blue-600 hover:underline">{{ $robawsCache->email }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Phone</p>
                    <p class="text-sm text-gray-800">
                        @if($robawsCache->phone)
                            <a href="tel:{{ $robawsCache->phone }}" class="text-blue-600 hover:underline">{{ $robawsCache->phone }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </p>
                </div>
                @if($robawsCache->mobile)
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Mobile</p>
                    <p class="text-sm text-gray-800">
                        <a href="tel:{{ $robawsCache->mobile }}" class="text-blue-600 hover:underline">{{ $robawsCache->mobile }}</a>
                    </p>
                </div>
                @endif
                @if($robawsCache->website)
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Website</p>
                    <p class="text-sm text-gray-800">
                        <a href="{{ $robawsCache->website }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                            {{ $robawsCache->website }}
                        </a>
                    </p>
                </div>
                @endif
            </div>

            {{-- Address --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5 pb-5 border-b border-gray-100">
                <div class="sm:col-span-2">
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Address</p>
                    <p class="text-sm text-gray-800">
                        @php
                            $addressParts = array_filter([
                                trim(($robawsCache->street ?? '') . ' ' . ($robawsCache->street_number ?? '')),
                                $robawsCache->city,
                                trim(($robawsCache->postal_code ?? '') . ' ' . ($robawsCache->country ?? '')),
                            ]);
                        @endphp
                        @if($addressParts)
                            {{ implode(', ', $addressParts) }}
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </p>
                </div>
            </div>

            {{-- VAT --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                @if($robawsCache->vat_number)
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">VAT Number</p>
                    <p class="text-sm text-gray-800 font-mono">{{ $robawsCache->vat_number }}</p>
                </div>
                @endif
            </div>

            {{-- Last synced --}}
            @if($robawsCache->last_synced_at)
                <p class="text-xs text-gray-400 mt-2">
                    <i class="fas fa-sync-alt mr-1"></i>Last synced: {{ $robawsCache->last_synced_at->diffForHumans() }}
                </p>
            @endif

        @else
            <div class="flex items-start gap-3 p-4 bg-yellow-50 rounded-lg border border-yellow-100">
                <i class="fas fa-exclamation-triangle text-yellow-500 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-yellow-800">No company profile linked</p>
                    <p class="text-sm text-yellow-700 mt-1">Your account is not yet linked to a company in Robaws CRM. Please contact your account manager.</p>
                </div>
            </div>
        @endif
    </div>

    {{-- ── Account Information ─────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <section>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-user mr-2 text-blue-500"></i>Account Information
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __("Update your login name and email address.") }}
                </p>
            </header>

            <form id="send-verification" method="post" action="{{ route('verification.send') }}">
                @csrf
            </form>

            <form method="post" action="{{ route('profile.update') }}" class="space-y-5">
                @csrf
                @method('patch')

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Name') }}</label>
                    <input id="name" name="name" type="text"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                           value="{{ old('name', $user->name) }}" required autofocus autocomplete="name" />
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Email') }}</label>
                    <input id="email" name="email" type="email"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                           value="{{ old('email', $user->email) }}" required autocomplete="username" />
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div class="mt-2">
                            <p class="text-sm text-gray-800">
                                {{ __('Your email address is unverified.') }}
                                <button form="send-verification" class="underline text-sm text-blue-600 hover:text-blue-800">
                                    {{ __('Click here to re-send the verification email.') }}
                                </button>
                            </p>
                            @if (session('status') === 'verification-link-sent')
                                <p class="mt-2 font-medium text-sm text-green-600">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        {{ __('Save') }}
                    </button>

                    @if (session('status') === 'profile-updated')
                        <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                           class="text-sm text-green-600 font-medium">
                            <i class="fas fa-check mr-1"></i>{{ __('Saved.') }}
                        </p>
                    @endif
                </div>
            </form>
        </section>
    </div>

    {{-- ── Update Password ─────────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg p-6 mb-8">
        <section>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-lock mr-2 text-blue-500"></i>Update Password
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Ensure your account is using a long, random password to stay secure.') }}
                </p>
            </header>

            <form method="post" action="{{ route('password.update') }}" class="space-y-5">
                @csrf
                @method('put')

                <div>
                    <label for="update_password_current_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Current Password') }}</label>
                    <input id="update_password_current_password" name="current_password" type="password"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                           autocomplete="current-password" />
                    @if ($errors->updatePassword->has('current_password'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->updatePassword->first('current_password') }}</p>
                    @endif
                </div>

                <div>
                    <label for="update_password_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('New Password') }}</label>
                    <input id="update_password_password" name="password" type="password"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                           autocomplete="new-password" />
                    @if ($errors->updatePassword->has('password'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->updatePassword->first('password') }}</p>
                    @endif
                </div>

                <div>
                    <label for="update_password_password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">{{ __('Confirm Password') }}</label>
                    <input id="update_password_password_confirmation" name="password_confirmation" type="password"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                           autocomplete="new-password" />
                    @if ($errors->updatePassword->has('password_confirmation'))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->updatePassword->first('password_confirmation') }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        {{ __('Save') }}
                    </button>

                    @if (session('status') === 'password-updated')
                        <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
                           class="text-sm text-green-600 font-medium">
                            <i class="fas fa-check mr-1"></i>{{ __('Saved.') }}
                        </p>
                    @endif
                </div>
            </form>
        </section>
    </div>

</div>
@endsection
