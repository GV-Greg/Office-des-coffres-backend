{{--
    Comptes que la purge (accounts:purge) devait supprimer mais qu'elle laisse en place parce qu'un
    personnage tient un mandat en cours (brief politique-promesses §2.6, fil Q8) : la purge ne
    décide pas seule. Liste réécrite à chaque passage ; muette quand elle est vide.
--}}
@php($blockedAccounts = \Illuminate\Support\Facades\Cache::get(\App\Console\Commands\PurgeAccounts::BLOCKED_CACHE_KEY, []))
@if($blockedAccounts !== [])
    <div role="alert" class="mb-4 rounded border border-amber-400 bg-amber-900 p-3 text-sm text-amber-100">
        <p class="font-semibold">{{ __('accounts.dashboard.blocked_title') }}</p>
        <ul class="list-disc pl-5">
            @foreach($blockedAccounts as $account)
                <li>{{ __('accounts.dashboard.blocked_line', [
                    'id' => $account['id'],
                    'email' => $account['email'],
                    'characters' => implode(', ', $account['characters']),
                ]) }}</li>
            @endforeach
        </ul>
        <p class="text-xs">{{ __('accounts.dashboard.blocked_help') }}</p>
    </div>
@endif
