{{-- Animation — administration : types d'activité (activer / désactiver), activités récentes (surveillance) et recours pour les
     activités SANS COMPTE (clore, retirer avec confirmation, lien de réinitialisation). Aucun texte en dur. --}}
<x-app-layout>
    <x-slot name="header">
        {{ __('anim.admin.title') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <p role="status" class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</p>
            @endif
            @if($errors->any())
                <div role="alert" class="text-sm text-red-700 dark:text-red-300">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            {{-- Le lien de réinitialisation n'est montré QU'ICI et UNE SEULE FOIS (flash) : à copier tout de suite. --}}
            @if(session('reset_link'))
                <div role="status" class="bg-yellow-50 dark:bg-yellow-900 border border-yellow-600 rounded p-4 text-sm text-gray-900 dark:text-gray-100 space-y-2">
                    <p class="font-bold">{{ session('reset_link')['heading'] }}</p>
                    <textarea readonly rows="3" class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-xs font-mono">{{ session('reset_link')['message'] }}</textarea>
                </div>
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
                                    <th class="py-2">{{ __('anim.admin.mode') }}</th>
                                    <th class="py-2">{{ __('anim.admin.organizer') }}</th>
                                    <th class="py-2 hidden md:table-cell">{{ __('anim.admin.email') }}</th>
                                    <th class="py-2 hidden md:table-cell">{{ __('anim.admin.place') }}</th>
                                    <th class="py-2">{{ __('anim.admin.created') }}</th>
                                    <th class="py-2">{{ __('anim.admin.state') }}</th>
                                    <th class="py-2 hidden md:table-cell">{{ __('anim.admin.last_reset') }}</th>
                                    <th class="py-2">{{ __('anim.admin.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($activities as $activity)
                                    <tr class="border-b border-gray-100 dark:border-gray-600 align-top">
                                        <td class="py-2">{{ $activity->name }}<br><code class="text-xs">{{ $activity->slug }}</code></td>
                                        <td class="py-2">{{ $activity->is_anonymous ? __('anim.admin.mode_anonymous') : __('anim.admin.mode_private') }}</td>
                                        <td class="py-2">
                                            {{ $activity->is_anonymous ? $activity->organizer_name : ($activity->character?->pseudo ?? __('anim.admin.organizer_deleted')) }}
                                            {{-- L'auteur sans email a demandé un lien : à lui remettre AU PSEUDO, dans le jeu (D36). --}}
                                            @if($activity->reset_requested_at)
                                                <br><span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-yellow-200 text-yellow-900 text-xs font-bold" data-testid="reset-requested">{{ __('anim.admin.reset_requested', ['date' => $activity->reset_requested_at->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i')]) }}</span>
                                            @endif
                                        </td>
                                        {{-- D32 : l'adresse n'est lue que si elle est CONFIRMÉE ; une adresse non confirmée n'est jamais utilisée ni montrée. --}}
                                        <td class="py-2 hidden md:table-cell">
                                            @if($activity->hasVerifiedEmail())
                                                {{ $activity->organizer_email }}
                                            @elseif($activity->organizer_email !== null)
                                                {{ __('anim.admin.email_unconfirmed') }}
                                            @else
                                                {{ __('anim.admin.email_none') }}
                                            @endif
                                        </td>
                                        <td class="py-2 hidden md:table-cell">{{ $activity->kingdom?->kingdom_name ?? $activity->province?->province_name ?? $activity->city?->city_name ?? '—' }}</td>
                                        <td class="py-2">{{ $activity->created_at->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i') }}</td>
                                        <td class="py-2">{{ __('anim.status.'.$activity->status) }}</td>
                                        <td class="py-2 hidden md:table-cell">
                                            @if($activity->password_reset_at)
                                                {{ $activity->password_reset_at->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i') }}
                                                ({{ $activity->reset_mode === 'email' ? __('anim.admin.reset_via_email') : __('anim.admin.reset_via_admin') }})
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2">
                                            {{-- Recours réservé aux activités SANS COMPTE (D13) ; les privées n'ont aucun geste ici. --}}
                                            @if($activity->is_anonymous)
                                                <div class="flex flex-wrap gap-1">
                                                    @unless($activity->isClosed())
                                                        <form method="POST" action="{{ route('anim.activities.reset-link', $activity) }}">
                                                            @csrf
                                                            <button type="submit" class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs uppercase font-bold whitespace-nowrap">{{ __('anim.admin.reset_link') }}</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('anim.activities.close', $activity) }}">
                                                            @csrf
                                                            <button type="submit" class="px-2 py-1 rounded bg-gray-600 hover:bg-gray-700 text-white text-xs uppercase font-bold">{{ __('anim.admin.close') }}</button>
                                                        </form>
                                                    @endunless
                                                    <a href="{{ route('anim.activities.delete', $activity) }}" class="px-2 py-1 rounded bg-red-700 hover:bg-red-800 text-white text-xs uppercase font-bold">{{ __('anim.admin.delete') }}</a>
                                                </div>
                                            @endif
                                        </td>
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
