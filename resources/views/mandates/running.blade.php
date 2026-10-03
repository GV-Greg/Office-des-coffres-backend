@php
    use App\Support\MandateLabels;
    $input = 'rounded border-gray-600 bg-gray-800 text-gray-100 text-sm';
    $button = 'px-3 py-1 rounded text-white text-xs uppercase font-bold';
    $today = \App\Support\MandateCalendar::today();
@endphp

@component('mandates._layout', ['title' => __('mandates.admin.title_running')])
    <p class="text-xs text-gray-400">{{ __('mandates.admin.absence_warning') }}</p>
    <p class="text-xs text-gray-400">{{ __('mandates.admin.no_office_window') }}</p>

    @if($councilByProvince->isEmpty() && $mayors->isEmpty())
        <p class="text-sm">{{ __('mandates.admin.empty_running') }}</p>
    @endif

    @foreach($councilByProvince as $provinceId => $members)
        @php
            $province = $members->first()->province;
            $inOffice = $members->filter(fn ($m) => $m->isInOffice());
            $elected = $members->filter(fn ($m) => $m->effectiveStatus() === 'elected');
        @endphp
        <section class="space-y-2" aria-labelledby="province-{{ $provinceId }}">
            <h3 id="province-{{ $provinceId }}" class="flex items-baseline gap-3 font-semibold">
                {{ __('mandates.admin.council_of', ['place' => $province?->province_name]) }}
                <span class="text-xs font-normal text-gray-400">{{ trans_choice('mandates.admin.members', $members->count()) }}</span>
                {{-- Q1 : l'historique se consulte quand on regarde un conseil — un lien, pas un dépliant. --}}
                <a href="{{ route('mandates.history', ['province' => $provinceId]) }}" class="ml-auto text-xs font-normal text-blue-300 underline hover:text-blue-200">{{ __('mandates.admin.history_link') }} →</a>
            </h3>

            @foreach($members as $mandate)
                <x-mandate.row :pseudo="$mandate->character?->pseudo" :post="MandateLabels::office($mandate->currentOfficeKey(), 'fr')">
                    @if($mandate->hasOfficeRequest())
                        <x-slot:sub><span class="text-sm text-gray-300">· {{ __('mandates.admin.requested_office') }} : {{ MandateLabels::office($mandate->pendingOffice?->key, 'fr') }}</span></x-slot:sub>
                    @endif
                    <x-slot:badges>@include('mandates._status_chip', ['mandate' => $mandate])</x-slot:badges>

                    @include('mandates._mandate_facts', ['mandate' => $mandate])
                    <div class="mt-3 grid gap-3 lg:grid-cols-5 items-start">
                        <x-mandate.panel tone="blue" :title="__('mandates.admin.set_office_title')" class="lg:col-span-3">
                            @if($mandate->isInOffice())
                                <form method="POST" action="{{ route('mandates.council.office', ['id' => $mandate->id]) }}" class="space-y-2">
                                    @csrf
                                    <label class="text-xs text-gray-300 block">
                                        <span class="block mb-0.5">{{ __('mandates.admin.set_office') }}</span>
                                        <select name="council_office_key" class="w-full max-w-sm {{ $input }}">
                                            <option value="">{{ __('mandates.admin.remove_office') }}</option>
                                            @foreach($offices as $office)
                                                <option value="{{ $office->key }}">{{ MandateLabels::office($office->key, 'fr') }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <p class="text-xs text-gray-400">{{ __('mandates.admin.remove_office_help') }}</p>
                                    @include('mandates._reason_fields', ['reasons' => config('mandates.office_remove_reasons')])
                                    <button type="submit" class="{{ $button }} bg-blue-700 hover:bg-blue-800">{{ __('mandates.admin.apply') }}</button>
                                </form>
                            @else
                                <p class="text-xs text-gray-400">{{ __('mandates.admin.office_not_in_office_help') }}</p>
                            @endif
                        </x-mandate.panel>

                        <x-mandate.panel tone="gray" :title="__('mandates.admin.correct_dates_title')" class="lg:col-span-2">
                            <form method="POST" action="{{ route('mandates.correct-start', ['level' => 'council', 'id' => $mandate->id]) }}" class="flex flex-wrap gap-2 items-end">
                                @csrf
                                <label class="text-xs text-gray-300 grow"><span class="block mb-0.5">{{ __('mandates.admin.retained_start') }}</span>
                                    <input type="date" name="started_at" value="{{ $mandate->started_at?->format('Y-m-d') }}" max="{{ $today }}" class="w-full {{ $input }}"></label>
                                <button type="submit" class="{{ $button }} bg-gray-600 hover:bg-gray-500">{{ __('mandates.admin.correct') }}</button>
                            </form>
                            <form method="POST" action="{{ route('mandates.council.in-office', ['id' => $mandate->id]) }}" class="flex flex-wrap gap-2 items-end">
                                @csrf
                                <label class="text-xs text-gray-300 grow"><span class="block mb-0.5">{{ __('mandates.admin.in_office_from') }}</span>
                                    <input type="date" name="in_office_from" value="{{ $mandate->in_office_from?->setTimezone(config('mandates.timezone'))->format('Y-m-d') }}" max="{{ $today }}" class="w-full {{ $input }}"></label>
                                <button type="submit" class="{{ $button }} bg-gray-600 hover:bg-gray-500">{{ __('mandates.admin.correct') }}</button>
                            </form>
                        </x-mandate.panel>

                        <div class="lg:col-span-5">@include('mandates._revoke_form', ['mandate' => $mandate])</div>
                    </div>
                </x-mandate.row>
            @endforeach

            {{-- Gestes sur toute la province : repliés comme une ligne, en orange (ils touchent tout le conseil). --}}
            <details class="group rounded-lg border border-orange-500/40 bg-orange-900/10 open:bg-orange-900/20">
                <summary class="flex items-center gap-3 px-4 py-2.5 cursor-pointer list-none rounded-lg hover:bg-orange-900/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-300">
                    <span class="flex-1 text-sm font-semibold text-orange-200">{{ __('mandates.admin.province_actions') }}
                        <span class="font-normal text-xs text-orange-200/80">— {{ __('mandates.admin.province_actions_help') }}</span></span>
                    <span aria-hidden="true" class="text-orange-300 transition-transform group-open:rotate-90">▸</span>
                </summary>
                <div class="grid gap-3 lg:grid-cols-3 px-4 pb-4 pt-3 border-t border-orange-500/30">
                    <x-mandate.panel tone="amber" :title="__('mandates.admin.extend_title')">
                        <form method="POST" action="{{ route('mandates.provinces.extend', $provinceId) }}" class="space-y-2">
                            @csrf
                            <label class="text-xs text-gray-300 block"><span class="block mb-0.5">{{ __('mandates.admin.extend_note') }}</span>
                                <input type="text" name="note" required class="w-full {{ $input }}"></label>
                            <button type="submit" class="{{ $button }} bg-amber-700 hover:bg-amber-800">{{ __('mandates.admin.extend', ['days' => config('mandates.council_extension_days')]) }}</button>
                        </form>
                    </x-mandate.panel>

                    <x-mandate.panel tone="orange" :title="__('mandates.admin.handover_title')">
                        <form method="POST" action="{{ route('mandates.provinces.handover', $provinceId) }}" class="space-y-2">
                            @csrf
                            <label class="text-xs text-gray-300 block"><span class="block mb-0.5">{{ __('mandates.admin.handover') }}</span>
                                <input type="date" name="date" required max="{{ $today }}" class="{{ $input }}"></label>
                            <div class="grid grid-cols-2 gap-3">
                            <fieldset class="text-xs text-gray-200">
                                <legend class="text-gray-400">{{ __('mandates.admin.outgoing') }}</legend>
                                @forelse($inOffice as $member)
                                    <label class="block"><input type="checkbox" name="outgoing[]" value="{{ $member->id }}" checked> {{ $member->character?->pseudo }}</label>
                                @empty
                                    <p>—</p>
                                @endforelse
                            </fieldset>
                            <fieldset class="text-xs text-gray-200">
                                <legend class="text-gray-400">{{ __('mandates.admin.incoming') }}</legend>
                                @forelse($elected as $member)
                                    <label class="block"><input type="checkbox" name="incoming[]" value="{{ $member->id }}" checked> {{ $member->character?->pseudo }}</label>
                                @empty
                                    <p>—</p>
                                @endforelse
                            </fieldset>
                            </div>
                            <p class="text-xs text-gray-400">{{ __('mandates.admin.handover_confirm') }}</p>
                            <button type="submit" class="{{ $button }} bg-orange-700 hover:bg-orange-800">{{ __('mandates.admin.handover_submit') }}</button>
                        </form>
                    </x-mandate.panel>

                    <x-mandate.panel tone="orange" :title="__('mandates.admin.clear_offices_title')">
                        <form method="POST" action="{{ route('mandates.provinces.clear-offices', $provinceId) }}" class="space-y-2">
                            @csrf
                            <label class="text-xs text-gray-300 block"><span class="block mb-0.5">{{ __('mandates.admin.clear_offices') }}</span>
                                <input type="date" name="date" required max="{{ $today }}" class="{{ $input }}"></label>
                            <p class="text-xs text-gray-400">{{ __('mandates.admin.clear_offices_help') }}</p>
                            <button type="submit" class="{{ $button }} bg-orange-700 hover:bg-orange-800">{{ __('mandates.admin.clear_offices_submit') }}</button>
                        </form>
                    </x-mandate.panel>
                </div>
            </details>
        </section>
    @endforeach

    @if($mayors->isNotEmpty())
        <section class="space-y-2" aria-labelledby="mayors-title">
            <h3 id="mayors-title" class="flex items-baseline gap-3 font-semibold">
                {{ __('mandates.admin.mayors') }}
                <span class="text-xs font-normal text-gray-400">{{ trans_choice('mandates.admin.members', $mayors->count()) }}</span>
            </h3>
            @foreach($mayors as $mandate)
                <x-mandate.row :pseudo="$mandate->character?->pseudo" :post="MandateLabels::post($mandate, 'fr')">
                    <x-slot:sub><span class="text-xs text-gray-400">({{ $mandate->city?->province?->province_name }})</span></x-slot:sub>
                    <x-slot:badges>@include('mandates._status_chip', ['mandate' => $mandate])</x-slot:badges>

                    @include('mandates._mandate_facts', ['mandate' => $mandate])
                    <div class="mt-3 grid gap-3 lg:grid-cols-2 items-start">
                        <x-mandate.panel tone="gray" :title="__('mandates.admin.correct_start_title')">
                            <form method="POST" action="{{ route('mandates.correct-start', ['level' => 'mayor', 'id' => $mandate->id]) }}" class="flex flex-wrap gap-2 items-end">
                                @csrf
                                <label class="text-xs text-gray-300"><span class="block mb-0.5">{{ __('mandates.admin.retained_start') }}</span>
                                    <input type="date" name="started_at" value="{{ $mandate->started_at?->format('Y-m-d') }}" max="{{ $today }}" class="{{ $input }}"></label>
                                <button type="submit" class="{{ $button }} bg-gray-600 hover:bg-gray-500">{{ __('mandates.admin.correct') }}</button>
                            </form>
                        </x-mandate.panel>
                        @include('mandates._revoke_form', ['mandate' => $mandate])
                    </div>
                </x-mandate.row>
            @endforeach
        </section>
    @endif
@endcomponent
