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

        <h3 class="text-xl md:text-2xl">
            {{ __('Get connected!') }}
        </h3>

        <!-- Formulaire -->
        <form method="POST" action="{{ route('login') }}">
            @csrf

            <!-- Email Address -->
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <!-- Password -->
            <div class="mt-4">
                <x-input-label for="password" :value="__('Password')" />

                <x-text-input id="password" class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required autocomplete="current-password" />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Remember Me -->
            <div class="block mt-4">
                <label for="remember_me" class="inline-flex items-center label-checkbox">
                    <input id="remember_me" type="checkbox" class="form-checkbox dark:dark-form-checkbox focus:ring-blue-500 dark:focus:ring-blue-600 dark:focus:ring-offset-blueGray-800" name="remember">
                    <span class="ms-2 text-sm dark:dark-span">{{ __('Remember me') }}</span>
                </label>
            </div>

            <!-- Button to log -->
            <div class="form-group mt-4">
                <x-button class="btn-blue py-2">
                    {{ __('Log in') }}
                </x-button>
            </div>

            <!-- Forgotten password -->
            <div class="form-group mt-4">
                @if (Route::has('password.request'))
                    <a class="text-sm text-center font-bold text-blue-600 hover:text-blue-900" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>
        </form>
    </x-auth-card>
</x-guest-layout>
