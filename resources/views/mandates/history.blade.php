@php
    use App\Support\MandateLabels;
@endphp

@component('mandates._layout', ['title' => __('mandates.admin.title_history')])
    {{-- Q3 et 04 §10a : ce que l'Office ne connaît pas n'est pas vacant. En tête, pas en bas de page. --}}
    <p class="rounded border border-amber-400/40 bg-amber-900/20 px-3 py-2 text-sm text-amber-100">{{ __('mandates.admin.absence_warning') }}</p>

    <form method="GET" action="{{ route('mandates.history') }}" class="flex flex-wrap items-end gap-3">
        <label class="text-xs text-gray-300">
            <span class="block mb-0.5">{{ __('mandates.admin.history_province') }}</span>
            <select name="province" class="rounded border-gray-600 bg-gray-800 text-gray-100 text-sm min-w-[18rem]" onchange="this.form.submit()">
                <option value="">{{ __('mandates.admin.history_pick_province') }}</option>
                @foreach($provinces->groupBy(fn ($p) => $p->kingdom?->kingdom_name) as $kingdom => $list)
                    <optgroup label="{{ $kingdom }}">
                        @foreach($list as $option)
                            <option value="{{ $option->id }}" @selected($province?->id === $option->id)>{{ $option->province_name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>
        <noscript><button type="submit" class="px-3 py-1 rounded text-white text-xs uppercase font-bold bg-gray-600">{{ __('mandates.admin.history_show') }}</button></noscript>
    </form>

    @if($province)
        <section class="space-y-3" aria-labelledby="history-council">
            <h3 id="history-council" class="font-semibold">{{ __('mandates.admin.council_of', ['place' => $province->province_name]) }}</h3>
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach($offices as $office)
                    @php($holders = $council->get($office->key, collect()))
                    <div class="rounded-md border border-gray-700 bg-gray-900/30">
                        <p class="px-3 py-2 text-sm font-semibold text-indigo-200 border-b border-gray-700">{{ MandateLabels::office($office->key, 'fr') }}</p>
                        @if($holders->isEmpty())
                            <p class="px-3 py-1.5 text-xs text-gray-400">{{ __('mandates.admin.history_no_holder') }}</p>
                        @else
                            <ul class="divide-y divide-gray-800">
                                @foreach($holders as $entry)
                                    @include('mandates._history_entry', ['entry' => $entry])
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="history-mayors">
            <h3 id="history-mayors" class="font-semibold">{{ __('mandates.admin.history_mayors', ['place' => $province->province_name]) }}</h3>
            @if($mayorCities->isEmpty())
                <p class="text-sm text-gray-400">{{ __('mandates.admin.history_no_mayor') }}</p>
            @else
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach($mayorCities as $city)
                        <div class="rounded-md border border-gray-700 bg-gray-900/30">
                            <p class="px-3 py-2 text-sm font-semibold text-teal-200 border-b border-gray-700">{{ $city->city_name }}</p>
                            <ul class="divide-y divide-gray-800">
                                @foreach($mayors->get($city->id) as $entry)
                                    @include('mandates._history_entry', ['entry' => $entry])
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($citiesWithout->isNotEmpty())
                <details class="group rounded-md border border-gray-700 bg-gray-900/20">
                    <summary class="flex items-center gap-2 px-3 py-2 cursor-pointer list-none text-sm text-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-300 rounded-md">
                        <span class="flex-1">{{ trans_choice('mandates.admin.history_cities_without', $citiesWithout->count()) }}</span>
                        <span aria-hidden="true" class="transition-transform group-open:rotate-90">▸</span>
                    </summary>
                    <p class="px-3 pb-3 text-sm text-gray-400">{{ $citiesWithout->pluck('city_name')->join(', ') }}</p>
                </details>
            @endif
        </section>
    @endif
@endcomponent
