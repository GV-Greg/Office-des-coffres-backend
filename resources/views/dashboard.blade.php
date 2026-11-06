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

            {{-- 6 CARTES COMPTEURS --}}
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">

                {{-- Comptes --}}
                <a href="{{ route('users') }}" class="block bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-3">
                        <x-ri-group-fill class="w-8 h-8 text-blue-400 shrink-0"/>
                        <div>
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['users'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.card_users') }}</div>
                        </div>
                    </div>
                    @if($counts['users_unverified'] > 0)
                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-2">
                            {{ trans_choice('dashboard.card_users_unverified', $counts['users_unverified'], ['count' => $counts['users_unverified']]) }}
                        </div>
                    @endif
                </a>

                {{-- Personnages --}}
                <a href="{{ route('characters') }}" class="block bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-3">
                        <x-ri-user-fill class="w-8 h-8 text-green-400 shrink-0"/>
                        <div>
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['characters'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.card_characters') }}</div>
                        </div>
                    </div>
                    @if($counts['characters_pending'] > 0)
                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-2">
                            {{ __('dashboard.card_characters_pending', ['count' => $counts['characters_pending']]) }}
                        </div>
                    @endif
                </a>

                {{-- Mandats actifs --}}
                <a href="{{ route('mandates.pending') }}" class="block bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-3">
                        <x-ri-user-star-fill class="w-8 h-8 text-indigo-400 shrink-0"/>
                        <div>
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['mandates_running'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.card_mandates') }}</div>
                        </div>
                    </div>
                    @if($counts['mandates_pending'] > 0)
                        <div class="text-xs text-amber-600 dark:text-amber-400 mt-2">
                            {{ __('dashboard.card_mandates_pending', ['count' => $counts['mandates_pending']]) }}
                        </div>
                    @endif
                </a>

                {{-- Relevés mines — non-cliquable --}}
                <div class="bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4 border border-dashed border-gray-300 dark:border-gray-600 cursor-default">
                    <div class="flex items-center gap-3">
                        <x-ri-file-list-3-fill class="w-8 h-8 text-amber-400 shrink-0"/>
                        <div>
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['mine_reports_active'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.card_mine_reports') }}</div>
                        </div>
                    </div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-2">
                        @if($counts['mine_report_latest'])
                            {{ __('dashboard.card_mine_reports_latest', ['date' => \Carbon\Carbon::parse($counts['mine_report_latest'])->format('d/m/Y')]) }}
                        @else
                            {{ __('dashboard.card_mine_reports_none') }}
                        @endif
                    </div>
                </div>

                {{-- Coffres scellés --}}
                <a href="{{ route('anim.index') }}" class="block bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-3">
                        <x-gameicon-chest class="w-8 h-8 text-teal-400 shrink-0 [&>path:first-child]:fill-transparent [&>path:last-child]:fill-current"/>
                        <div>
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['secrets_open'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.card_secrets') }}</div>
                        </div>
                    </div>
                    @if($counts['secrets_found_week'] > 0)
                        <div class="text-xs text-green-600 dark:text-green-400 mt-2">
                            {{ trans_choice('dashboard.card_secrets_found', $counts['secrets_found_week'], ['count' => $counts['secrets_found_week']]) }}
                        </div>
                    @endif
                </a>

                {{-- Provinces avec prix — non-cliquable --}}
                <div class="bg-white dark:bg-gray-700 shadow-sm rounded-lg p-4 border border-dashed border-gray-300 dark:border-gray-600 cursor-default">
                    <div class="flex items-center gap-3">
                        <x-ri-money-dollar-circle-fill class="w-8 h-8 text-amber-400 shrink-0"/>
                        <div>
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">{{ $counts['provinces_with_prices'] }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.card_prices') }}</div>
                        </div>
                    </div>
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
