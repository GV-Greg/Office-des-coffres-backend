{{-- Douaniers — niveau 3 de la validation (module Sécurité). Dernier recours : l'administrateur décide « sur demande ». --}}
<x-app-layout>
    <x-slot name="header">
        {{ __('security.admin.title') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="w-3/4 mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <p role="status" class="text-sm text-green-700 dark:text-green-300">{{ session('status') }}</p>
            @endif
            @if($errors->any())
                <div role="alert" class="text-sm text-red-700 dark:text-red-300">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('security.admin.intro') }}</p>

            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-4">{{ __('security.admin.pending_heading') }} ({{ $pending->count() }})</h2>
                    @forelse($pending as $row)
                        <div class="flex items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-600 py-2">
                            <span>{{ $row->character?->pseudo }} — {{ $row->province?->province_name }} / {{ $row->city?->city_name }}</span>
                            <span class="flex gap-2">
                                <form method="POST" action="{{ route('customs.approve', $row->id) }}">@csrf
                                    <button type="submit" class="px-3 py-1 rounded bg-green-700 text-white">{{ __('security.admin.approve') }}</button>
                                </form>
                                <form method="POST" action="{{ route('customs.reject', $row->id) }}">@csrf
                                    <button type="submit" class="px-3 py-1 rounded bg-red-700 text-white">{{ __('security.admin.reject') }}</button>
                                </form>
                            </span>
                        </div>
                    @empty
                        <p class="text-sm">{{ __('security.admin.none_pending') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-4">{{ __('security.admin.approved_heading') }} ({{ $approved->count() }})</h2>
                    @forelse($approved as $row)
                        <div class="flex items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-600 py-2">
                            <span>
                                {{ $row->character?->pseudo }} — {{ $row->province?->province_name }} / {{ $row->city?->city_name }}
                                @if($officers->isInactive($row))
                                    <strong class="text-orange-700 dark:text-orange-300">· {{ __('security.admin.inactive') }}</strong>
                                @endif
                            </span>
                            <form method="POST" action="{{ route('customs.revoke', $row->id) }}">@csrf
                                <button type="submit" class="px-3 py-1 rounded bg-red-700 text-white">{{ __('security.admin.revoke') }}</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm">{{ __('security.admin.none_approved') }}</p>
                    @endforelse
                </div>
            </section>

            {{-- Retrait d'un pseudo (outil interne hors politique) : des NOMBRES, jamais un contenu. --}}
            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg" data-testid="remove-pseudo">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-2">{{ __('security.admin.remove_pseudo_title') }}</h2>
                    <p class="text-sm mb-4">{{ __('security.admin.remove_pseudo_intro') }}</p>

                    @isset($removal)
                        <p role="status" class="text-sm text-green-700 dark:text-green-300 mb-3" data-testid="removal-simulation">
                            {{ __('security.admin.simulation_result', ['pseudo' => $removal['pseudo'], 'entries' => $removal['entries'], 'reports' => $removal['reports'], 'occurrences' => $removal['occurrences']]) }}
                        </p>
                        @if($removal['entries'] + $removal['reports'] > 0)
                            <form method="POST" action="{{ route('customs.remove-pseudo.apply') }}" class="mb-4"
                                  onsubmit="return confirm(@js(__('security.admin.remove_confirm')))">@csrf
                                <input type="hidden" name="pseudo" value="{{ $removal['pseudo'] }}">
                                <button type="submit" class="px-3 py-1 rounded bg-red-700 text-white">{{ __('security.admin.remove_definitely') }}</button>
                            </form>
                        @endif
                    @endisset

                    <form method="POST" action="{{ route('customs.remove-pseudo.preview') }}" class="flex items-end gap-2">@csrf
                        <label class="text-sm">
                            <span class="block">{{ __('security.admin.pseudo_label') }}</span>
                            <input type="text" name="pseudo" required maxlength="40" pattern="\S{1,40}" autocomplete="off"
                                   class="rounded border-gray-300 text-gray-900" data-testid="remove-pseudo-input">
                        </label>
                        <button type="submit" class="px-3 py-1 rounded bg-blue-700 text-white">{{ __('security.admin.simulate') }}</button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
