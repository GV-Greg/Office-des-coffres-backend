@php
    use App\Models\CouncilMandate;
    use App\Support\MandateLabels;
    $input = 'rounded border-gray-600 bg-gray-800 text-gray-100 text-sm';
    $button = 'px-3 py-1 rounded text-white text-xs uppercase font-bold';
@endphp

@component('mandates._layout', ['title' => __('mandates.admin.title_pending')])
    <p class="text-xs text-gray-600 dark:text-gray-300">{{ __('mandates.admin.absence_warning') }}</p>

    <section aria-labelledby="requests-title" class="space-y-2">
        <h3 id="requests-title" class="font-semibold">{{ __('mandates.admin.requests') }}</h3>
        @if($requests->isNotEmpty())
            <p class="text-xs text-gray-400">{{ __('mandates.admin.expand_hint') }}</p>
        @endif

        @forelse($requests as $mandate)
            @php
                $key = $mandate::LEVEL.':'.$mandate->id;
                $info = $insights[$key];
                $isCouncil = $mandate instanceof CouncilMandate;
            @endphp
            @php
                $alerts = collect([
                    $info['dead_born'] ? ['red', __('mandates.admin.badge_dead_born')] : null,
                    $info['renewed_revoked'] ? ['red', __('mandates.admin.badge_renewed_revoked')] : null,
                    $info['seat_holders']->isNotEmpty() ? ['amber', __('mandates.admin.badge_seat_taken')] : null,
                    $info['outgoing_extended'] ? ['amber', __('mandates.admin.badge_outgoing_extended')] : null,
                    $info['off_residence'] ? ['blue', __('mandates.admin.badge_off_residence')] : null,
                    $info['renewal'] ? ['gray', __('mandates.admin.badge_renewal')] : null,
                ])->filter();
                $waiting = (int) $mandate->created_at?->diffInDays(now());
                $notes = collect([
                    $info['seat_holders']->isNotEmpty() ? __('mandates.admin.seat_taken_by', ['holders' => $info['seat_holders']->map(fn ($h) => $h->character?->pseudo)->join(', ')]) : null,
                    $info['title_holder'] ? __('mandates.admin.office_held_by', ['holder' => $info['title_holder']->character?->pseudo]) : null,
                    $info['unassigned_over'] ? __('mandates.admin.unassigned_over', ['count' => config('mandates.council_unassigned_seats')]) : null,
                    $info['outgoing_extended'] ? __('mandates.admin.leader_question') : null,
                ])->filter();
            @endphp
            <x-mandate.row :level="$mandate::LEVEL" :pseudo="$mandate->character?->pseudo" :post="MandateLabels::post($mandate, 'fr')">
                @if($info['renewal'])
                    <x-slot:lead>
                        <input type="checkbox" form="batch-form" name="items[]" value="{{ $key }}" onclick="event.stopPropagation()"
                               aria-label="{{ __('mandates.admin.batch_select', ['pseudo' => $mandate->character?->pseudo]) }}">
                    </x-slot:lead>
                @endif
                @if($isCouncil && $mandate->hasOfficeRequest())
                    <x-slot:sub><span class="text-sm text-gray-300">· {{ __('mandates.admin.requested_office') }} : {{ MandateLabels::office($mandate->pendingOffice?->key, 'fr') }}</span></x-slot:sub>
                @endif
                <x-slot:badges>
                    @foreach($alerts as [$tone, $text])<x-mandate.chip :tone="$tone">{{ $text }}</x-mandate.chip>@endforeach
                </x-slot:badges>
                <x-slot:meta>
                    <span class="shrink-0 text-xs text-gray-300 tabular-nums">{{ $mandate->declared_started_at?->format('d/m/Y') }}</span>
                    <span class="shrink-0 text-xs {{ $waiting >= config('mandates.verification_days.'.$mandate::LEVEL) ? 'text-red-300 font-semibold' : 'text-gray-400' }}">{{ trans_choice('mandates.admin.received_ago', $waiting) }}</span>
                </x-slot:meta>

                <div class="grid gap-4 lg:grid-cols-5">
                    {{-- Dossier --}}
                    <section class="lg:col-span-2 space-y-3" aria-label="{{ __('mandates.admin.file') }}">
                        <h4 class="text-xs font-bold uppercase tracking-wide text-gray-400 ">{{ __('mandates.admin.file') }}</h4>
                        <dl class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-1.5 text-sm">
                            <dt class="text-gray-400">{{ __('mandates.admin.account') }}</dt><dd class="text-gray-100 break-all">{{ $mandate->character?->user?->email }}</dd>
                            <dt class="text-gray-400">{{ __('mandates.admin.residence') }}</dt><dd class="text-gray-100">{{ $mandate->character?->city?->city_name }} ({{ $mandate->character?->city?->province?->province_name }})</dd>
                            <dt class="text-gray-400">{{ __('mandates.admin.declared_start') }}</dt><dd class="text-gray-100 tabular-nums">{{ $mandate->declared_started_at?->format('d/m/Y') }}</dd>
                            <dt class="text-gray-400">{{ __('mandates.admin.would_end') }}</dt><dd class="text-gray-100 tabular-nums">{{ MandateLabels::date($info['valid_until']) }}</dd>
                        </dl>
                        <a href="{{ $mandate->announcement_url }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 rounded border border-blue-400/60 px-3 py-1.5 text-sm font-semibold text-blue-200 hover:bg-blue-900/40"
                           title="{{ $mandate->announcement_url }}">
                            {{ __('mandates.admin.open_announcement') }} <span aria-hidden="true">↗</span>
                        </a>
                        <p class="text-xs text-gray-400 break-all">{{ $mandate->announcement_url }}</p>

                        @if($notes->isNotEmpty())
                            <div class="rounded border border-amber-400/50 bg-amber-900/30 p-3 text-sm text-amber-100 space-y-1">
                                <p class="text-xs font-bold uppercase tracking-wide">{{ __('mandates.admin.attention') }}</p>
                                @foreach($notes as $note)<p>{{ $note }}</p>@endforeach
                            </div>
                        @endif
                    </section>

                    {{-- Décision : valider et refuser côte à côte, jamais l'un caché dans l'autre. --}}
                    <section class="lg:col-span-3 space-y-3" aria-label="{{ __('mandates.admin.decision') }}">
                        <h4 class="text-xs font-bold uppercase tracking-wide text-gray-400 ">{{ __('mandates.admin.decision') }}</h4>

                        <x-mandate.panel tone="green" :title="__('mandates.admin.approve_title')">
                        <form method="POST" action="{{ route('mandates.approve', ['level' => $mandate::LEVEL, 'id' => $mandate->id]) }}" class="space-y-2">
                            @csrf
                            <div class="flex flex-wrap gap-3 items-end">
                                <label class="text-xs text-gray-300">
                                    <span class="block mb-0.5">{{ __('mandates.admin.retained_start') }}</span>
                                    <input type="date" name="started_at" value="{{ $mandate->started_at?->format('Y-m-d') }}" class="{{ $input }}">
                                </label>
                                @if($isCouncil)
                                    <label class="text-xs text-gray-300">
                                        <span class="block mb-0.5">{{ __('mandates.admin.in_office_from') }}</span>
                                        <input type="date" name="in_office_from" class="{{ $input }}">
                                    </label>
                                @endif
                                <label class="text-xs text-gray-300 grow min-w-[12rem]">
                                    <span class="block mb-0.5">{{ __('mandates.admin.note') }}</span>
                                    <input type="text" name="note" class="w-full {{ $input }}">
                                </label>
                                <button type="submit" class="{{ $button }} bg-green-700 hover:bg-green-800">{{ __('mandates.admin.approve') }}</button>
                            </div>
                            @if($isCouncil)
                                <p class="text-xs text-gray-400">{{ __('mandates.admin.in_office_help') }}</p>
                                @if($mandate->pending_office_id)
                                    <p class="text-xs text-amber-200">{{ __('mandates.admin.title_dropped_warning') }}</p>
                                @endif
                            @elseif($info['seat_holders']->isNotEmpty())
                                <label class="text-sm flex gap-2 items-center text-gray-200">
                                    <input type="hidden" name="replace_holder" value="0">
                                    <input type="checkbox" name="replace_holder" value="1">
                                    {{ __('mandates.admin.replace_holder') }}
                                </label>
                            @endif
                        </form>
                        </x-mandate.panel>

                        <x-mandate.panel tone="red" :title="__('mandates.admin.reject_title')">
                        <form method="POST" action="{{ route('mandates.reject', ['level' => $mandate::LEVEL, 'id' => $mandate->id]) }}" class="space-y-2">
                            @csrf
                            @include('mandates._reason_fields', ['reasons' => config('mandates.reject_reasons')])
                            <button type="submit" class="{{ $button }} bg-red-700 hover:bg-red-800">{{ __('mandates.admin.reject') }}</button>
                        </form>
                        </x-mandate.panel>
                    </section>
                </div>
            </x-mandate.row>
        @empty
            <p class="text-sm">{{ __('mandates.admin.empty_pending') }}</p>
        @endforelse

        @if($requests->contains(fn ($m) => $m->renews_id !== null))
            <form id="batch-form" method="POST" action="{{ route('mandates.batch-approve') }}">
                @csrf
                <button type="submit" class="{{ $button }} bg-green-700 hover:bg-green-800">{{ __('mandates.admin.batch_approve') }}</button>
                <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">{{ __('mandates.admin.batch_only_renewals') }}</p>
            </form>
        @endif
    </section>

    <section aria-labelledby="office-requests-title" class="space-y-2">
        <h3 id="office-requests-title" class="font-semibold">{{ __('mandates.admin.office_requests') }}</h3>

        @forelse($officeRequests as $mandate)
            <x-mandate.row level="council" :pseudo="$mandate->character?->pseudo" :post="__('mandates.admin.council_of', ['place' => $mandate->province?->province_name])">
                <x-slot:sub><span class="text-sm text-gray-300">· {{ __('mandates.admin.requested_office') }} : <strong class="text-gray-100">{{ MandateLabels::office($mandate->pendingOffice?->key, 'fr') }}</strong></span></x-slot:sub>
                @if($officeHolders[$mandate->id])
                    <x-slot:badges><x-mandate.chip tone="amber">{{ __('mandates.admin.badge_office_taken') }}</x-mandate.chip></x-slot:badges>
                @endif
                <x-slot:meta>
                    <span class="shrink-0 text-xs text-gray-400">{{ trans_choice('mandates.admin.received_ago', (int) $mandate->office_requested_at?->diffInDays(now())) }}</span>
                </x-slot:meta>

                @if($officeHolders[$mandate->id])
                    <p class="mb-3 rounded border border-amber-400/50 bg-amber-900/30 p-3 text-sm text-amber-100">{{ __('mandates.admin.office_held_by', ['holder' => $officeHolders[$mandate->id]->character?->pseudo]) }}</p>
                @endif
                <div class="grid gap-3 lg:grid-cols-2">
                    <x-mandate.panel tone="green" :title="__('mandates.admin.approve_office_title')">
                        <form method="POST" action="{{ route('mandates.council.office.approve', ['id' => $mandate->id]) }}">
                            @csrf
                            <button type="submit" class="{{ $button }} bg-green-700 hover:bg-green-800">{{ __('mandates.admin.approve') }}</button>
                        </form>
                    </x-mandate.panel>
                    <x-mandate.panel tone="red" :title="__('mandates.admin.reject_office_title')">
                        <form method="POST" action="{{ route('mandates.council.office.reject', ['id' => $mandate->id]) }}" class="space-y-2">
                            @csrf
                            @include('mandates._reason_fields', ['reasons' => config('mandates.reject_reasons')])
                            <button type="submit" class="{{ $button }} bg-red-700 hover:bg-red-800">{{ __('mandates.admin.reject') }}</button>
                        </form>
                    </x-mandate.panel>
                </div>
            </x-mandate.row>
        @empty
            <p class="text-sm">{{ __('mandates.admin.empty_office_requests') }}</p>
        @endforelse
    </section>
@endcomponent
