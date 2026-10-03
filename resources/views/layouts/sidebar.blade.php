<nav class="fixed left-0 top-16 bottom-0 z-10 w-14 bg-gray-100 dark:bg-gray-800">
    @if(Auth::user()->hasRole('admin'))
        <x-nav-link-sidebar :href="route('users')" :active="request()->is('users*')">
            <i class="fa-solid fa-users"></i>
        </x-nav-link-sidebar>
        @php($pendingMandates = \App\Http\Controllers\Web\MandateAdminController::pendingCount())
        {{-- Pastille accrochée au COIN DE LA TUILE (le lien est `relative`), pas à l'icône : sa place
             ne dépend plus de la police d'icônes. Cercle de 18 px, chiffre centré, plafonné à 9+.
             Blanc sur red-600 ≈ 4,8:1 ; filet blanc pour la détacher du bleu de la tuile. --}}
        <x-nav-link-sidebar :href="route('mandates.pending')" :active="request()->is('mandates*')" class="relative" title="{{ __('mandates.admin.nav') }}">
            {{-- Buste étoilé (Remix Icon « user-star-fill ») : la même icône que les postes du Profil côté
                 joueur. Absente de Font Awesome, d'où le SVG en ligne ; 1em = taille des autres icônes. --}}
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="w-[1.15em] h-[1.15em]"><path d="M12 14v8H4a8 8 0 018-8zm6 7.5l-2.939 1.545.561-3.272-2.377-2.318 3.286-.478L18 14l1.47 2.977 3.285.478-2.377 2.318.56 3.272L18 21.5zM12 13c-3.315 0-6-2.685-6-6s2.685-6 6-6 6 2.685 6 6-2.685 6-6 6z"/></svg>
            <span class="sr-only">{{ __('mandates.admin.nav_count', ['count' => $pendingMandates]) }}</span>
            @if($pendingMandates > 0)
                <span aria-hidden="true" class="absolute top-2 right-1.5 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 border border-white text-[10px] font-bold leading-none text-white tabular-nums">{{ $pendingMandates > 9 ? '9+' : $pendingMandates }}</span>
            @endif
        </x-nav-link-sidebar>
    @endif
</nav>
