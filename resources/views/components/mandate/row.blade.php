{{--
    Ligne dépliable des pages Mandats (admin). <details> natif : clavier et lecteurs d'écran sans
    JavaScript. Repliée, de quoi trier d'un coup d'œil ; dépliée, le dossier et les gestes.
    Slots : `lead` (case à cocher…), `sub` (après le poste), `badges`, `meta` (dates, ancienneté).
    Le back-office n'a qu'un thème sombre : les couleurs sont écrites pour lui.
--}}
@props(['level' => null, 'pseudo', 'post' => null])
<details {{ $attributes->merge(['class' => 'group rounded-lg border border-gray-600 bg-gray-800/40 open:bg-gray-800/70 open:border-gray-500']) }}>
    <summary class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 cursor-pointer list-none rounded-lg hover:bg-gray-700/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-300">
        {{ $lead ?? '' }}
        @if($level)
            <span class="shrink-0 w-16 text-center text-[11px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5 {{ $level === 'council' ? 'bg-indigo-900/70 text-indigo-100' : 'bg-teal-900/70 text-teal-100' }}">
                {{ $level === 'council' ? __('mandates.admin.level_council') : __('mandates.admin.level_mayor') }}
            </span>
        @endif
        <span class="min-w-0 flex-1">
            <strong class="text-gray-50">{{ $pseudo }}</strong>
            @if($post)<span class="text-sm text-gray-300">— {{ $post }}</span>@endif
            {{ $sub ?? '' }}
        </span>
        @isset($badges)<span class="flex flex-wrap gap-1">{{ $badges }}</span>@endisset
        {{ $meta ?? '' }}
        <span aria-hidden="true" class="shrink-0 text-gray-400 transition-transform group-open:rotate-90">▸</span>
    </summary>
    <div class="px-4 pb-4 pt-3 border-t border-gray-700">
        {{ $slot }}
    </div>
</details>
