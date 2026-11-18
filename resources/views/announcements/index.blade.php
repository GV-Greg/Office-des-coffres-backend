{{-- Annonces du site (18/11/2026) : prévenir d'une maintenance, le message défile sur l'accueil de l'application. --}}
<x-app-layout>
    <x-slot name="header">
        {{ __('announcements.title') }}
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

            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-1">{{ __('announcements.add_heading') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('announcements.subtitle') }}</p>
                    @include('announcements._form', ['announcement' => $announcement, 'action' => route('announcements.store'), 'method' => null])
                </div>
            </section>

            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-4">{{ __('announcements.title') }}</h2>

                    @if($announcements->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('announcements.empty') }}</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                    <th class="py-2 pr-4">{{ __('announcements.col_status') }}</th>
                                    <th class="py-2 pr-4">{{ __('announcements.col_message') }}</th>
                                    <th class="py-2 pr-4">{{ __('announcements.col_visibility') }}</th>
                                    <th class="py-2 text-right">{{ __('announcements.col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($announcements as $item)
                                    @php $status = $item->statusAt($now); @endphp
                                    <tr class="border-b border-gray-100 dark:border-gray-600 align-top">
                                        <td class="py-2 pr-4 whitespace-nowrap font-semibold
                                            {{ $status === 'visible' ? 'text-green-700 dark:text-green-300' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ __('announcements.status.'.$status) }}
                                        </td>
                                        <td class="py-2 pr-4">{{ $item->renderMessage('fr', $now) }}</td>
                                        <td class="py-2 pr-4 text-xs text-gray-600 dark:text-gray-300">
                                            {{ __('announcements.visible_from_label', ['date' => \App\Models\SiteAnnouncement::toParisInput($item->visible_from)]) }}
                                            @if($item->effectiveEnd())
                                                <br>{{ __('announcements.visible_until_label', ['date' => \App\Models\SiteAnnouncement::toParisInput($item->effectiveEnd())]) }}
                                            @endif
                                        </td>
                                        <td class="py-2 text-right whitespace-nowrap">
                                            <a href="{{ route('announcements.edit', $item) }}" class="text-blue-700 dark:text-blue-300 underline mr-2">{{ __('announcements.edit') }}</a>
                                            <form method="POST" action="{{ route('announcements.toggle', $item) }}" class="inline">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="underline mr-2">{{ $item->is_active ? __('announcements.disable') : __('announcements.enable') }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('announcements.destroy', $item) }}" class="inline"
                                                  onsubmit="return confirm(@js(__('announcements.delete_confirm')))">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-700 dark:text-red-300 underline">{{ __('announcements.delete') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="mt-4">{{ $announcements->links() }}</div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
