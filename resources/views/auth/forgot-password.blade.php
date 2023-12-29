<x-guest-layout>
    <x-auth-card>
        <x-slot name="logo">
            <h1 class="my-2 text-4xl md:text-5xl">
                Office des coffres
            </h1>
            <h2 class="text-3xl md:text-4xl">
                {{ __('Administration') }}
            </h2>
        </x-slot>

        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <div class="mb-4 text-sm text-blueGray-600">
            {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
        </div>

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <!-- Email Address -->
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <!-- Button to log -->
            <div class="form-group mt-4">
                <x-button class="btn btn-blue py-2">
                    {{ __('Email Password Reset Link') }}
                </x-button>
            </div>
        </form>
    </x-auth-card>
</x-guest-layout>
