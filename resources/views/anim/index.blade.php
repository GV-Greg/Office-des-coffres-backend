{{-- Animation — administration : types d'activité (activer / désactiver) et activités récentes (surveillance). Aucun texte en dur. --}}
<x-app-layout>
    <x-slot name="header">
        {{ __('anim.admin.title') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <p role="status" class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</p>
            @endif

            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-1">{{ __('anim.admin.types_title') }}</h2>
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">{{ __('anim.admin.types_help') }}</p>

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                <th class="py-2">{{ __('anim.admin.type') }}</th>
                                <th class="py-2">{{ __('anim.admin.key') }}</th>
                                <th class="py-2">{{ __('anim.admin.state') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($types as $type)
                                <tr class="border-b border-gray-100 dark:border-gray-600">
                                    <td class="py-2">{{ __('anim.types.'.$type->key.'.name') }}</td>
                                    <td class="py-2"><code>{{ $type->key }}</code></td>
                                    <td class="py-2">{{ $type->is_active ? __('anim.admin.active') : __('anim.admin.inactive') }}</td>
                                    <td class="py-2 text-right">
                                        <form method="POST" action="{{ route('anim.types.toggle', $type) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit"
                                                    class="px-3 py-1 rounded text-white text-xs uppercase font-bold whitespace-nowrap {{ $type->is_active ? 'bg-gray-600 hover:bg-gray-700' : 'bg-blue-600 hover:bg-blue-700' }}">
                                                {{ $type->is_active ? __('anim.admin.deactivate') : __('anim.admin.activate') }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-1">{{ __('anim.admin.recent_title') }}</h2>
                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">{{ __('anim.admin.recent_help') }}</p>

                    @if($activities->isEmpty())
                        <p class="text-sm">{{ __('anim.admin.recent_empty') }}</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                    <th class="py-2">{{ __('anim.admin.activity') }}</th>
                                    <th class="py-2 hidden md:table-cell">{{ __('anim.admin.type') }}</th>
                                    <th class="py-2">{{ __('anim.admin.organizer') }}</th>
                                    <th class="py-2 hidden md:table-cell">{{ __('anim.admin.place') }}</th>
                                    <th class="py-2">{{ __('anim.admin.created') }}</th>
                                    <th class="py-2">{{ __('anim.admin.state') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($activities as $activity)
                                    <tr class="border-b border-gray-100 dark:border-gray-600">
                                        <td class="py-2">{{ $activity->name }}</td>
                                        <td class="py-2 hidden md:table-cell">{{ __('anim.types.'.$activity->type->key.'.name') }}</td>
                                        <td class="py-2">{{ $activity->character?->pseudo ?? __('anim.admin.organizer_deleted') }}</td>
                                        <td class="py-2 hidden md:table-cell">{{ $activity->province?->province_name ?? $activity->city?->city_name ?? '—' }}</td>
                                        <td class="py-2">{{ $activity->created_at->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i') }}</td>
                                        <td class="py-2">{{ __('anim.status.'.$activity->status) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
