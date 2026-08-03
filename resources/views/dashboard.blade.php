<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    @if(session('status') === 'character-validated')
                        <p class="mb-4 text-sm text-green-600 dark:text-green-400">
                            <i class="fa-solid fa-circle-check"></i>
                            {{ __('Character validated.') }}
                        </p>
                    @elseif(session('status') === 'character-invalidated')
                        <p class="mb-4 text-sm text-orange-600 dark:text-orange-400">
                            <i class="fa-solid fa-rotate-left"></i>
                            {{ __('Character invalidated.') }}
                        </p>
                    @endif

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                <th class="py-2 hidden md:table-cell">#</th>
                                <th class="py-2">{{ __('Pseudo') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('Email') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('Kingdom') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('Province') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('City') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('Registered') }}</th>
                                <th class="py-2 text-center">{{ __('Status') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($characters as $character)
                                <x-table-character :character="$character" />
                            @empty
                                <tr>
                                    <td colspan="9" class="py-6 text-center text-gray-500">{{ __('No character yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>

                    <div class="mt-4">
                        {{ $characters->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
