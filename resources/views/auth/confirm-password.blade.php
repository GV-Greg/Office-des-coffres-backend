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

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
        </div>

        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <!-- Password -->
            <div>
                <x-input-label for="password" :value="__('Password')" />

                <x-text-input id="password" class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required autocomplete="current-password" />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Button to confirm -->
            <div class="form-group mt-4">
                <x-button class="btn-blue py-2">
                    {{ __('Confirm') }}
                </x-button>
            </div>
        </form>
    </x-auth-card>
</x-guest-layout>
