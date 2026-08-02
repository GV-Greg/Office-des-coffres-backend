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

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </div>
        @endif

        <div class="mt-4 flex items-stretch gap-3">
            <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
                @csrf

                <button type="submit" class="w-full h-full px-3 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold text-center focus:outline-none">
                    {{ __('Resend Verification Email') }}
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="flex-1">
                @csrf

                <button type="submit" class="w-full h-full px-3 py-2 rounded bg-red-600 hover:bg-red-700 text-white text-sm font-bold text-center focus:outline-none">
                    {{ __('Log Out') }}
                </button>
            </form>
        </div>
    </x-auth-card>
</x-guest-layout>
