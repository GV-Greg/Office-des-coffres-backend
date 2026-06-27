<x-app-layout>
    <x-slot name="header">
        {{ __('Users without character') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <table class="w-full text-sm">
                        @if(session('status') === 'user-deleted')
                            <p class="mb-4 text-sm text-green-600 dark:text-green-400">{{ __('User deleted.') }}</p>
                        @endif

                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                <th class="py-2 hidden md:table-cell">#</th>
                                <th class="py-2">{{ __('Email') }}</th>
                                <th class="py-2 hidden md:table-cell">{{ __('Registered') }}</th>
                                <th class="py-2 text-center">{{ __('Verified') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $user)
                                <tr class="border-b border-gray-100 dark:border-gray-600">
                                    <td class="py-2 hidden md:table-cell">{{ $user->id }}</td>
                                    <td class="py-2 font-bold">{{ $user->email }}</td>
                                    <td class="py-2 hidden md:table-cell">{{ $user->created_at->format('j M Y') }}</td>
                                    <td class="py-2 text-center">
                                        @if($user->hasVerifiedEmail())
                                            <span class="px-2 py-1 rounded bg-green-500 text-white text-xs uppercase font-bold">{{ __('Yes') }}</span>
                                        @else
                                            <span class="px-2 py-1 rounded bg-red-500 text-white text-xs uppercase font-bold">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right">
                                        <form method="POST" action="{{ route('users.destroy', $user) }}"
                                              onsubmit="return confirm('{{ __('Delete this user?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs uppercase font-bold">
                                                {{ __('Delete') }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-gray-500">{{ __('No users without character.') }}</td>
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
