{{-- Une ligne d'historique (OfficeHistory::entry). La cause de fin est TOUJOURS dite (Q4) ; une
     réattribution ressort en ambre : c'est la trace d'un conflit de déclaration (04, §10a). --}}
@php
    use App\Support\MandateLabels;
    $reason = $entry['end_reason'];
    $tone = match (true) {
        $entry['ongoing'] => 'green',
        $reason === 'reattribue' => 'amber',
        in_array($reason, config('mandates.revoke_reasons'), true) => 'red',
        default => 'gray',
    };
@endphp
<li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-1.5 text-sm {{ $reason === 'reattribue' && ! $entry['ongoing'] ? 'bg-amber-900/20' : '' }}">
    <span class="min-w-[9rem] font-semibold text-gray-100">{{ $entry['pseudo'] }}</span>
    <span class="tabular-nums text-gray-300">{{ __('mandates.admin.history_period', [
        'from' => MandateLabels::date($entry['started_at']),
        'to' => $entry['ended_at'] ? MandateLabels::date($entry['ended_at']) : '…',
    ]) }}</span>
    <x-mandate.chip :tone="$tone">{{ $entry['ongoing'] ? __('mandates.admin.history_ongoing') : MandateLabels::reason($reason, 'fr') }}</x-mandate.chip>
    @if($entry['archived'])<x-mandate.chip tone="gray">{{ __('mandates.admin.history_archived') }}</x-mandate.chip>@endif
</li>
