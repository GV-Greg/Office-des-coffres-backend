<x-app-layout>
    <x-slot name="header">
        {{ __('Users') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    @if(session('status') === 'user-deleted')
                        <p class="mb-4 text-sm text-green-600 dark:text-green-400">{{ __('User deleted.') }}</p>
                    @endif

                    <form method="GET" action="{{ route('users') }}" class="mb-4 flex gap-2">
                        <input type="text" name="search" value="{{ $search }}"
                               placeholder="{{ __('Search by email or pseudo...') }}"
                               class="flex-grow rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm">
                        <button type="submit"
                                class="px-3 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs uppercase font-bold whitespace-nowrap">
                            {{ __('Search') }}
                        </button>
                        @if($search !== '')
                            <a href="{{ route('users') }}"
                               class="px-3 py-1 rounded bg-gray-400 hover:bg-gray-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                                {{ __('Reset') }}
                            </a>
                        @endif
                    </form>

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                <th class="py-2 hidden md:table-cell">#</th>
                                <th class="py-2">{{ __('Email') }}</th>
                                <th class="py-2">{{ __('Verified') }}</th>
                                <th class="py-2">{{ __('Character(s)') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('Registered') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $user)
                                <tr class="border-b border-gray-100 dark:border-gray-600">
                                    <td class="py-2 hidden md:table-cell">{{ $user->id }}</td>
                                    <td class="py-2 font-bold">{{ $user->email }}</td>
                                    <td class="py-2">
                                        @if($user->hasVerifiedEmail())
                                            <span class="px-2 py-1 rounded bg-green-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                                                <i class="fa-solid fa-circle-check"></i>
                                                {{ __('Verified') }}
                                            </span>
                                        @else
                                            <span class="px-2 py-1 rounded bg-red-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                                                <i class="fa-solid fa-clock"></i>
                                                {{ __('Not verified') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        @forelse($user->characters as $character)
                                            <div class="flex items-center gap-2 whitespace-nowrap {{ !$loop->last ? 'mb-1' : '' }}">
                                                <span>{{ $character->pseudo }}</span>
                                                @if($character->is_validated)
                                                    <span class="px-2 py-1 rounded bg-green-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                                                        <i class="fa-solid fa-circle-check"></i>
                                                        {{ __('Validated') }}
                                                    </span>
                                                @else
                                                    <span class="px-2 py-1 rounded bg-red-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                                                        <i class="fa-solid fa-clock"></i>
                                                        {{ __('Not validated') }}
                                                    </span>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-gray-400 italic">{{ __('No character') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="py-2 hidden md:table-cell">{{ $user->created_at->format('j M Y') }}</td>
                                    <td class="py-2 text-right">
                                        <a href="{{ route('users.destroy', $user) }}" data-confirm-delete
                                           class="inline-block px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs uppercase font-bold cursor-pointer">
                                            {{ __('Delete') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-gray-500">
                                        {{ $search !== '' ? __('No users match your search.') : __('No users found.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>

                    <div class="mt-4">
                        {{ $users->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
