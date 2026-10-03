{{-- Révoquer, avec ou sans successeur ; date d'effet maintenant par défaut, jamais dans le futur (Q1).
     Replié par défaut : geste rare et destructif, comme la zone dangereuse du Profil. --}}
<details class="group rounded-md border border-red-500/40 bg-red-950/20 open:bg-red-900/20">
    <summary class="flex items-center gap-2 px-3 py-2 cursor-pointer list-none text-sm font-semibold text-red-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-300 rounded-md">
        <span class="flex-1">{{ __('mandates.admin.revoke_title') }}…</span>
        <span aria-hidden="true" class="transition-transform group-open:rotate-90">▸</span>
    </summary>
    <form method="POST" action="{{ route('mandates.revoke', ['level' => $mandate::LEVEL, 'id' => $mandate->id]) }}" class="space-y-3 px-3 pb-3 max-w-3xl">
        @csrf
        <div class="flex flex-wrap gap-3 items-end">
            <label class="text-xs text-gray-300">
                <span class="block mb-0.5">{{ __('mandates.admin.effective_at') }}</span>
                <input type="date" name="effective_at" max="{{ \App\Support\MandateCalendar::today() }}"
                       class="rounded border-gray-600 bg-gray-800 text-gray-100 text-sm">
            </label>
            <p class="text-xs text-gray-400 pb-2.5 max-w-xs">{{ __('mandates.admin.effective_at_help') }}</p>
        </div>
        @include('mandates._reason_fields', ['reasons' => config('mandates.revoke_reasons')])
        <label class="text-xs text-gray-300 block">
            <span class="block mb-0.5">{{ __('mandates.admin.note') }}</span>
            <input type="text" name="note" class="w-full rounded border-gray-600 bg-gray-800 text-gray-100 text-sm">
        </label>
        <button type="submit" class="px-3 py-1 rounded text-white text-xs uppercase font-bold bg-red-700 hover:bg-red-800">{{ __('mandates.admin.revoke') }}</button>
    </form>
</details>
