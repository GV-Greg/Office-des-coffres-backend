{{-- Cadre commun des pages Mandats : onglets, message de retour, erreurs. Aucun texte en dur (Q5). --}}
<x-app-layout>
    <x-slot name="header">
        {{ $title }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="mb-4 flex flex-wrap gap-2 text-sm" aria-label="{{ __('mandates.admin.nav') }}">
                @foreach(['pending' => 'tab_pending', 'running' => 'tab_running', 'decisions' => 'tab_decisions', 'verification' => 'tab_verification', 'history' => 'tab_history'] as $route => $label)
                    <a href="{{ route('mandates.'.$route) }}"
                       @if(request()->routeIs('mandates.'.$route)) aria-current="page" @endif
                       class="px-3 py-1 rounded {{ request()->routeIs('mandates.'.$route) ? 'bg-orange-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100' }}">
                        {{ __('mandates.admin.'.$label) }}
                    </a>
                @endforeach
            </nav>

            <div class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100 space-y-6">
                    {{-- Commodité : le garde-fou réel est sur le tableau de bord (voir le partiel). --}}
                    @include('mandates._scheduler_status')
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

                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
