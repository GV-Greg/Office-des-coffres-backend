<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('dashboard.title') }}
        </h2>
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- BANDEAU D'ALERTES --}}
            @include('mandates._scheduler_status')
            @include('accounts._purge_blocked')

            @if($expiringMandates->isNotEmpty())
                <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-400 dark:border-amber-600 rounded-lg p-4 text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-semibold mb-1">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        {{ __('dashboard.alert_expiring_title') }}
                        <a href="{{ route('mandates.running') }}" class="ml-2 underline text-amber-700 dark:text-amber-300 hover:no-underline">{{ __('mandates.admin.nav_running') }}</a>
                    </p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($expiringMandates as $m)
                            <li>{{ __('dashboard.alert_expiring_line', $m) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($inactiveActivities->isNotEmpty())
                <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-400 dark:border-amber-600 rounded-lg p-4 text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-semibold mb-1">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        {{ __('dashboard.alert_inactive_title') }}
                        <a href="{{ route('anim.index') }}" class="ml-2 underline text-amber-700 dark:text-amber-300 hover:no-underline">{{ __('anim.admin.nav') }}</a>
                    </p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($inactiveActivities as $a)
                            <li>{{ __('dashboard.alert_inactive_line', $a) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- BANDEAU DE STATS : 6 cellules par domaine (3 × 2 sous lg avec filet gap-px, une ligne dès lg avec divide-x) --}}
            <div class="shadow-sm rounded-lg overflow-hidden grid grid-cols-3 gap-px bg-gray-200 dark:bg-gray-600 lg:flex lg:gap-0 lg:divide-x lg:divide-gray-200 lg:dark:divide-gray-600">

                {{-- Comptes --}}
                <a href="{{ route('users') }}" class="flex-1 p-4 bg-white dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                    <div class="flex items-center gap-2">
                        <x-ri-group-fill class="w-5 h-5 text-blue-400 shrink-0"/>
                        <span class="text-xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['users']['total'] }}</span>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.stat_users') }}</div>
                    @if($counts['users']['unverified'] > 0)
                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
                            {{ trans_choice('dashboard.stat_users_unverified', $counts['users']['unverified'], ['count' => $counts['users']['unverified']]) }}
                        </div>
                    @endif
                </a>

                {{-- Personnages --}}
                <a href="{{ route('characters') }}" class="flex-1 p-4 bg-white dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                    <div class="flex items-center gap-2">
                        <x-ri-user-fill class="w-5 h-5 text-green-400 shrink-0"/>
                        <span class="text-xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['characters']['total'] }}</span>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.stat_characters') }}</div>
                    @if($counts['characters']['pending'] > 0)
                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
                            {{ __('dashboard.stat_characters_pending', ['count' => $counts['characters']['pending']]) }}
                        </div>
                    @endif
                </a>

                {{-- Mandats --}}
                <a href="{{ route('mandates.pending') }}" class="flex-1 p-4 bg-white dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                    <div class="flex items-center gap-2">
                        <x-ri-user-star-fill class="w-5 h-5 text-indigo-400 shrink-0"/>
                        <span class="text-xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['mandates']['running'] }}</span>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.stat_mandates') }}</div>
                    @if($counts['mandates']['pending'] > 0)
                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
                            {{ __('dashboard.stat_mandates_pending', ['count' => $counts['mandates']['pending']]) }}
                        </div>
                    @endif
                </a>

                {{-- Économie — non cliquable --}}
                <div class="flex-1 p-4 bg-white dark:bg-gray-700 opacity-90 cursor-default">
                    <div class="flex items-center gap-2">
                        <x-ri-file-list-3-fill class="w-5 h-5 text-amber-400 shrink-0"/>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('dashboard.stat_economy') }}</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ trans_choice('dashboard.stat_economy_reports', $counts['economy']['reports'], ['count' => $counts['economy']['reports']]) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('dashboard.stat_economy_provinces', $counts['economy']['provinces'], ['count' => $counts['economy']['provinces']]) }}</div>
                    @if($counts['economy']['latest'])
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('dashboard.stat_economy_latest', ['date' => \Carbon\Carbon::parse($counts['economy']['latest'])->format('d/m')]) }}</div>
                    @endif
                </div>

                {{-- Animation --}}
                <a href="{{ route('anim.index') }}" class="flex-1 p-4 bg-white dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                    <div class="flex items-center gap-2">
                        <x-gameicon-chest class="w-5 h-5 text-teal-400 shrink-0 [&>path:first-child]:fill-transparent [&>path:last-child]:fill-current"/>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('dashboard.stat_animation') }}</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ trans_choice('dashboard.stat_animation_secrets', $counts['animation']['secrets'], ['count' => $counts['animation']['secrets']]) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('dashboard.stat_animation_grids', $counts['animation']['grids'], ['count' => $counts['animation']['grids']]) }}</div>
                </a>

                {{-- Sécurité — réservée (module Douane), non cliquable --}}
                <div class="flex-1 p-4 bg-white dark:bg-gray-700 opacity-90 cursor-default">
                    <div class="flex items-center gap-2">
                        <x-ri-shield-fill class="w-5 h-5 text-red-400 shrink-0"/>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('dashboard.stat_security') }}</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.stat_security_empty') }}</div>
                </div>

            </div>

            {{-- FIL D'ACTIVITÉ RÉCENTE --}}
            <div class="bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4">
                <h3 class="font-semibold text-left text-gray-700 dark:text-gray-200 mb-3">
                    {{ __('dashboard.recent_title') }}
                </h3>
                @if($recentActivity->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.recent_empty') }}</p>
                @else
                    @php
                        $tz = config('mandates.timezone', 'Europe/Paris');
                        $now = \Carbon\Carbon::now($tz);
                        $shownBuckets = [];
                        $bucketKeys = [
                            'today' => 'recent_today',
                            'week'  => 'recent_this_week',
                            'month' => 'recent_this_month',
                            'older' => 'recent_older',
                        ];
                    @endphp
                    <div class="divide-y divide-gray-100 dark:divide-gray-600">
                        @foreach($recentActivity as $event)
                            @php
                                $eventDate = $event['date']->copy()->setTimezone($tz);
                                $diffDays  = (int) $eventDate->diffInDays($now);
                                if ($eventDate->isToday()) {
                                    $bucket = 'today';
                                } elseif ($diffDays <= 7) {
                                    $bucket = 'week';
                                } elseif ($diffDays <= 30) {
                                    $bucket = 'month';
                                } else {
                                    $bucket = 'older';
                                }
                            @endphp
                            @if(!in_array($bucket, $shownBuckets))
                                @php $shownBuckets[] = $bucket; @endphp
                                <div class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide py-2 mt-2">
                                    {{ __('dashboard.' . $bucketKeys[$bucket]) }}
                                </div>
                            @endif
                            <div class="py-2 flex items-start gap-3 text-sm">
                                <span class="w-5 flex items-center justify-center {{ $event['color'] }} shrink-0 mt-0.5">
                                    @if(($event['icon_type'] ?? 'fa') === 'game')
                                        <x-dynamic-component
                                            :component="'gameicon-' . $event['icon']"
                                            class="w-5 h-5 [&>path:first-child]:fill-transparent [&>path:last-child]:fill-current"
                                        />
                                    @else
                                        <i class="fa-solid {{ $event['icon'] }}"></i>
                                    @endif
                                </span>
                                <span class="flex-1 text-gray-700 dark:text-gray-200 leading-snug">
                                    @if($event['url'])
                                        <a href="{{ $event['url'] }}" class="hover:underline">{{ $event['text'] }}</a>
                                    @else
                                        {{ $event['text'] }}
                                    @endif
                                </span>
                                <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap">
                                    {{ $event['date']->diffForHumans() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
