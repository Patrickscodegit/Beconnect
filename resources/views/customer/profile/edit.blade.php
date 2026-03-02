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

    <!-- Profile Information -->
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <section>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">{{ __('Profile Information') }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __("Update your account's profile information and email address.") }}
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

    <!-- Update Password -->
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <section>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">{{ __('Update Password') }}</h2>
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

    <!-- Delete Account -->
    <div class="bg-white shadow rounded-lg p-6 mb-8">
        <section class="space-y-6" x-data="{ showModal: false }">
            <header>
                <h2 class="text-lg font-medium text-gray-900">{{ __('Delete Account') }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
                </p>
            </header>

            <button @click="showModal = true"
                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                {{ __('Delete Account') }}
            </button>

            <!-- Confirmation Modal -->
            <div x-show="showModal || {{ $errors->userDeletion->isNotEmpty() ? 'true' : 'false' }}"
                 x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50"
                 @keydown.escape.window="showModal = false">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
                    <form method="post" action="{{ route('profile.destroy') }}">
                        @csrf
                        @method('delete')

                        <h2 class="text-lg font-medium text-gray-900 mb-2">
                            {{ __('Are you sure you want to delete your account?') }}
                        </h2>
                        <p class="text-sm text-gray-600 mb-6">
                            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm.') }}
                        </p>

                        <div class="mb-4">
                            <label for="delete_password" class="sr-only">{{ __('Password') }}</label>
                            <input id="delete_password" name="password" type="password"
                                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm"
                                   placeholder="{{ __('Password') }}" />
                            @if ($errors->userDeletion->has('password'))
                                <p class="mt-1 text-sm text-red-600">{{ $errors->userDeletion->first('password') }}</p>
                            @endif
                        </div>

                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showModal = false"
                                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                {{ __('Delete Account') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </div>

</div>
@endsection
