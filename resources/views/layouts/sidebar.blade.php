<nav class="fixed left-0 top-16 bottom-0 z-10 w-14 bg-gray-100 dark:bg-gray-800">
    @if(Auth::user()->hasRole('admin'))
        <x-nav-link-sidebar :href="route('users')" :active="request()->is('users*')">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
        </x-nav-link-sidebar>
        @php($pendingCharacters = \App\Http\Controllers\Web\DashboardController::pendingCharactersCount())
        <x-nav-link-sidebar :href="route('characters')" :active="request()->is('characters*')" class="relative" title="{{ __('dashboard.characters_nav') }}">
            <i class="fa-solid fa-user-secret" aria-hidden="true"></i>
            <span class="sr-only">{{ __('dashboard.characters_nav') }}</span>
            @if($pendingCharacters > 0)
                <span aria-hidden="true" class="absolute top-2 right-1.5 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 border border-white text-[10px] font-bold leading-none text-white tabular-nums">{{ $pendingCharacters > 9 ? '9+' : $pendingCharacters }}</span>
            @endif
        </x-nav-link-sidebar>
        @php($pendingMandates = \App\Http\Controllers\Web\MandateAdminController::pendingCount())
        {{-- Pastille accrochée au COIN DE LA TUILE (le lien est `relative`), pas à l'icône : sa place
             ne dépend plus de la police d'icônes. Cercle de 18 px, chiffre centré, plafonné à 9+.
             Blanc sur red-600 ≈ 4,8:1 ; filet blanc pour la détacher du bleu de la tuile. --}}
        <x-nav-link-sidebar :href="route('mandates.pending')" :active="request()->is('mandates*')" class="relative" title="{{ __('mandates.admin.nav') }}">
            <i class="fa-solid fa-user-tag" aria-hidden="true"></i>
            <span class="sr-only">{{ __('mandates.admin.nav_count', ['count' => $pendingMandates]) }}</span>
            @if($pendingMandates > 0)
                <span aria-hidden="true" class="absolute top-2 right-1.5 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 border border-white text-[10px] font-bold leading-none text-white tabular-nums">{{ $pendingMandates > 9 ? '9+' : $pendingMandates }}</span>
            @endif
        </x-nav-link-sidebar>
        {{-- Animation : types d'activité et surveillance des activités récentes. --}}
        <x-nav-link-sidebar :href="route('anim.index')" :active="request()->is('anim*')" title="{{ __('anim.admin.nav') }}">
            <x-gameicon-rolling-dice-cup class="w-[1.15em] h-[1.15em] [&>path:first-child]:fill-transparent [&>path:last-child]:fill-current"/>
            <span class="sr-only">{{ __('anim.admin.nav') }}</span>
        </x-nav-link-sidebar>
        {{-- Sécurité : douaniers (niveau 3 de la validation, « sur demande »). Font Awesome 6 `user-shield` (= Fa6UserShield de vue-icons) : le douanier, gardien de la défense. --}}
        @php($pendingCustoms = \App\Http\Controllers\Web\CustomsAdminController::pendingCount())
        <x-nav-link-sidebar :href="route('customs.index')" :active="request()->is('customs*')" class="relative" title="{{ __('security.admin.title') }}">
            <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
            <span class="sr-only">{{ __('security.admin.nav_count', ['count' => $pendingCustoms]) }}</span>
            @if($pendingCustoms > 0)
                <span aria-hidden="true" class="absolute top-2 right-1.5 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 border border-white text-[10px] font-bold leading-none text-white tabular-nums">{{ $pendingCustoms > 9 ? '9+' : $pendingCustoms }}</span>
            @endif
        </x-nav-link-sidebar>
    @endif
</nav>
