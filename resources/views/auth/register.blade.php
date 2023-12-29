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

        <h3 class="text-2xl md:text-3xl">
            {{ __('Register now!') }}
        </h3>

        <form method="POST" action="{{ route('register') }}">
            @csrf

            <!-- Email Address -->
            <div class="mt-4">
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <!-- Password -->
            <div class="mt-4">
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Confirm Password -->
            <div class="mt-4">
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>

            <div class="form-group mt-4">
                <x-button class="py-2">
                    {{ __('Register') }}
                </x-button>
            </div>

            <div class="form-group mt-4">
                <a class="text-sm text-center font-bold text-blue-600 hover:text-blue-900" href="{{ route('login') }}">
                    {{ __('Already registered?') }}
                </a>
            </div>
        </form>
    </x-auth-card>
</x-guest-layout>
