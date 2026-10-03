@php
    use App\Support\MandateLabels;
@endphp

@component('mandates._layout', ['title' => __('mandates.admin.title_verification')])
    <p class="text-xs text-gray-400">{{ __('mandates.admin.verification_help') }}</p>
    <p class="text-xs text-gray-400">{{ __('mandates.admin.verification_thresholds', [
        'mayor' => config('mandates.verification_days.mayor'),
        'council' => config('mandates.verification_days.council'),
    ]) }}</p>

    {{-- Un seul geste par ligne : pas d'accordéon, la ligne porte directement le bouton. --}}
    <ul class="space-y-2">
        @forelse($items as $item)
            @php($mandate = $item['mandate'])
            <li class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-4 py-3 {{ $item['overdue'] ? 'border-red-500/50 bg-red-900/10' : 'border-gray-600 bg-gray-800/40' }}">
                <span class="shrink-0 w-16 text-center text-[11px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5 {{ $mandate::LEVEL === 'council' ? 'bg-indigo-900/70 text-indigo-100' : 'bg-teal-900/70 text-teal-100' }}">
                    {{ $mandate::LEVEL === 'council' ? __('mandates.admin.level_council') : __('mandates.admin.level_mayor') }}
                </span>
                <span class="min-w-0 flex-1">
                    <strong class="text-gray-50">{{ $mandate->character?->pseudo }}</strong>
                    <span class="text-sm text-gray-300">— {{ MandateLabels::post($mandate, 'fr') }}</span>
                    <span class="block text-xs text-gray-400">{{ $mandate->character?->user?->email }}</span>
                </span>
                @if($item['overdue'])<x-mandate.chip tone="red">{{ __('mandates.admin.overdue') }}</x-mandate.chip>@endif
                <span class="shrink-0 text-xs text-gray-300">
                    {{ __('mandates.admin.ended_on', ['date' => MandateLabels::date($item['ended_at'])]) }}
                    — {{ __('mandates.admin.pending_for', ['days' => $item['days']]) }}
                </span>
                <form method="POST" action="{{ route('mandates.verify', ['level' => $mandate::LEVEL, 'id' => $mandate->id]) }}">
                    @csrf
                    <button type="submit" class="px-3 py-1 rounded text-white text-xs uppercase font-bold bg-green-700 hover:bg-green-800">{{ __('mandates.admin.verify') }}</button>
                </form>
            </li>
        @empty
            <li class="text-sm">{{ __('mandates.admin.empty_verification') }}</li>
        @endforelse
    </ul>
@endcomponent
