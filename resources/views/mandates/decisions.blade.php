@php
    use App\Models\Mandate;
    use App\Support\MandateLabels;
    $input = 'rounded border-gray-600 bg-gray-800 text-gray-100 text-sm';
    $button = 'px-3 py-1 rounded text-white text-xs uppercase font-bold';
@endphp

@component('mandates._layout', ['title' => __('mandates.admin.title_decisions')])
    <p class="text-xs text-gray-400">{{ __('mandates.admin.decisions_help') }}</p>

    <div class="space-y-2">
    @forelse($decisions as $mandate)
        @php($revoked = $mandate->status === Mandate::REVOKED)
        <x-mandate.row :level="$mandate::LEVEL" :pseudo="$mandate->character?->pseudo" :post="MandateLabels::post($mandate, 'fr')">
            <x-slot:badges>
                <x-mandate.chip tone="red">{{ __('mandates.admin.statuses.'.$mandate->status) }}</x-mandate.chip>
                <x-mandate.chip tone="gray">{{ MandateLabels::reason($mandate->decision_reason, 'fr') }}</x-mandate.chip>
            </x-slot:badges>
            <x-slot:meta>
                <span class="shrink-0 text-xs text-gray-300 tabular-nums">{{ __('mandates.admin.decided_on', ['date' => MandateLabels::date($revoked ? $mandate->revoked_at : $mandate->processed_at)]) }}</span>
            </x-slot:meta>

            <p class="text-sm text-gray-200">{{ __('mandates.admin.reason') }} : {{ MandateLabels::reason($mandate->decision_reason, 'fr') }}</p>
            @if($mandate->decision_message)
                <blockquote class="mt-1 border-l-2 border-gray-500 pl-3 text-sm text-gray-300">{{ $mandate->decision_message }} <span class="text-xs text-gray-400">({{ $mandate->decision_message_locale }})</span></blockquote>
            @endif

            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                <x-mandate.panel tone="blue" :title="__('mandates.admin.correct_decision_title')">
                    <form method="POST" action="{{ route('mandates.correct-decision', ['level' => $mandate::LEVEL, 'id' => $mandate->id]) }}" class="space-y-2">
                        @csrf
                        <p class="text-xs text-gray-400">{{ __('mandates.admin.correct_decision_help') }}</p>
                        @include('mandates._reason_fields', ['reasons' => $revoked ? config('mandates.revoke_reasons') : config('mandates.reject_reasons')])
                        <button type="submit" class="{{ $button }} bg-blue-700 hover:bg-blue-800">{{ __('mandates.admin.correct') }}</button>
                    </form>
                </x-mandate.panel>

                @if($revoked)
                    <x-mandate.panel tone="amber" :title="__('mandates.admin.unrevoke_title')">
                        @if($mandate->valid_until && $mandate->valid_until->isFuture())
                            <form method="POST" action="{{ route('mandates.unrevoke', ['level' => $mandate::LEVEL, 'id' => $mandate->id]) }}" class="flex flex-wrap gap-2 items-end">
                                @csrf
                                <label class="text-xs text-gray-300 grow"><span class="block mb-0.5">{{ __('mandates.admin.note') }}</span>
                                    <input type="text" name="note" class="w-full {{ $input }}"></label>
                                <button type="submit" class="{{ $button }} bg-amber-700 hover:bg-amber-800">{{ __('mandates.admin.unrevoke') }}</button>
                            </form>
                            <p class="text-xs text-gray-400">{{ __('mandates.admin.unrevoke_help') }}</p>
                        @else
                            <p class="text-xs text-gray-400">{{ __('mandates.admin.errors.unrevoke_after_term') }}</p>
                        @endif
                    </x-mandate.panel>
                @endif
            </div>
        </x-mandate.row>
    @empty
        <p class="text-sm">{{ __('mandates.admin.empty_decisions') }}</p>
    @endforelse
    </div>
@endcomponent
