{{-- Référentiel admin des icônes d'items (brief admin/content/brief-icones-items.md).
     Les icônes ne sont jamais hébergées sur ODC — seul l'ID Celsius est stocké, l'URL CDN est
     construite à la volée. Mention de propriété Celsius visible en bas de page. --}}
<x-app-layout>
    <x-slot name="header">
        {{ __('item_icons.title') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Flash --}}
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

            {{-- Formulaire d'ajout --}}
            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-4">{{ __('item_icons.add_heading') }}</h2>

                    <form method="POST" action="{{ route('item-icons.store') }}" class="space-y-4">
                        @csrf

                        {{-- Ligne 1 : les deux champs côte à côte --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="item_name" class="block text-sm font-medium mb-1">
                                    {{ __('item_icons.item_name') }} <span aria-hidden="true">*</span>
                                </label>
                                <input type="text"
                                       id="item_name" name="item_name"
                                       value="{{ old('item_name') }}"
                                       required maxlength="150"
                                       placeholder="{{ __('item_icons.item_name_help') }}"
                                       class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('item_icons.item_name_help') }}</p>
                            </div>

                            <div>
                                <label for="game_item_id" class="block text-sm font-medium mb-1">
                                    {{ __('item_icons.game_item_id') }} <span aria-hidden="true">*</span>
                                </label>
                                <input type="number"
                                       id="game_item_id" name="game_item_id"
                                       value="{{ old('game_item_id') }}"
                                       required min="1"
                                       placeholder="{{ __('item_icons.game_item_id_help') }}"
                                       class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       x-data
                                       x-on:input="$dispatch('preview-id', { id: $el.value })">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('item_icons.game_item_id_help') }}</p>
                            </div>
                        </div>

                        {{-- Ligne 2 : aperçu live + bouton --}}
                        <div x-data="iconPreview()"
                             x-on:preview-id.window="update($event.detail.id)"
                             class="flex items-center gap-4 pt-2">
                            <div>
                                <p class="text-xs font-medium mb-1">{{ __('item_icons.preview') }}</p>
                                <template x-if="url">
                                    <img :src="url" :alt="'{{ __('item_icons.preview_label') }}'"
                                         width="48" height="48"
                                         class="rounded border border-gray-200 dark:border-gray-600"
                                         x-on:error="broken = true; url = null">
                                </template>
                                <template x-if="!url && broken">
                                    <p class="text-xs text-red-600 dark:text-red-400">{{ __('item_icons.no_preview') }}</p>
                                </template>
                                <template x-if="!url && !broken">
                                    <div class="w-12 h-12 rounded border border-dashed border-gray-300 dark:border-gray-600"></div>
                                </template>
                            </div>
                            <button type="submit"
                                    class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded">
                                {{ __('item_icons.save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            {{-- Recherche --}}
            <form method="GET" action="{{ route('item-icons.index') }}" class="flex gap-2">
                <input type="search" name="search" value="{{ $search }}"
                       placeholder="{{ __('item_icons.search') }}"
                       class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm shadow-sm w-64 focus:ring-blue-500 focus:border-blue-500">
                <button type="submit"
                        class="px-3 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-bold rounded">
                    {{ __('item_icons.search_button') }}
                </button>
                @if($search)
                    <a href="{{ route('item-icons.index') }}"
                       class="px-3 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-100 text-sm rounded">
                        {{ __('item_icons.search_reset') }}
                    </a>
                @endif
            </form>

            {{-- Liste --}}
            <section class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="font-bold mb-4">{{ __('item_icons.title') }}</h2>

                    @if($icons->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('item_icons.empty') }}</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-600 text-left">
                                    <th class="py-2 pr-4 w-10">{{ __('item_icons.col_icon') }}</th>
                                    <th class="py-2 pr-4">{{ __('item_icons.col_item') }}</th>
                                    <th class="py-2 pr-4 w-28">{{ __('item_icons.col_id') }}</th>
                                    <th class="py-2 text-right">{{ __('item_icons.col_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($icons as $icon)
                                    <tr class="border-b border-gray-100 dark:border-gray-600 align-middle"
                                        x-data="{ editing: false }">
                                        {{-- Icône CDN --}}
                                        <td class="py-2 pr-4">
                                            <img src="{{ $icon->iconUrl() }}"
                                                 alt="{{ $icon->item_name }}"
                                                 width="32" height="32"
                                                 loading="lazy"
                                                 class="rounded"
                                                 onerror="this.style.display='none'">
                                        </td>

                                        {{-- Nom --}}
                                        <td class="py-2 pr-4 font-medium">{{ $icon->item_name }}</td>

                                        {{-- ID / formulaire d'édition --}}
                                        <td class="py-2 pr-4">
                                            <span x-show="!editing" class="font-mono">{{ $icon->game_item_id }}</span>

                                            <form x-show="editing"
                                                  method="POST"
                                                  action="{{ route('item-icons.update', $icon) }}"
                                                  class="flex items-center gap-1">
                                                @csrf
                                                @method('PATCH')
                                                <input type="number" name="game_item_id"
                                                       value="{{ $icon->game_item_id }}"
                                                       min="1" required
                                                       class="w-24 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-xs shadow-sm">
                                                <button type="submit"
                                                        class="px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded">
                                                    {{ __('item_icons.update') }}
                                                </button>
                                            </form>
                                        </td>

                                        {{-- Actions --}}
                                        <td class="py-2 text-right">
                                            <div class="flex justify-end gap-1">
                                                <button type="button"
                                                        x-show="!editing"
                                                        @click="editing = true"
                                                        class="px-2 py-1 bg-gray-500 hover:bg-gray-600 text-white text-xs font-bold rounded">
                                                    {{ __('item_icons.edit_heading') }}
                                                </button>
                                                <button type="button"
                                                        x-show="editing"
                                                        @click="editing = false"
                                                        class="px-2 py-1 bg-gray-400 hover:bg-gray-500 text-white text-xs font-bold rounded">
                                                    {{ __('item_icons.cancel') }}
                                                </button>

                                                <form method="POST"
                                                      action="{{ route('item-icons.destroy', $icon) }}"
                                                      onsubmit="return confirm('{{ __('item_icons.confirm_delete') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="px-2 py-1 bg-red-700 hover:bg-red-800 text-white text-xs font-bold rounded">
                                                        {{ __('item_icons.delete') }}
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <div class="mt-4">
                            {{ $icons->links() }}
                        </div>
                    @endif
                </div>
            </section>

            {{-- Copyright Celsius --}}
            <p class="text-xs text-gray-500 dark:text-gray-400 italic">
                <i class="fa-solid fa-circle-info mr-1"></i>{{ __('item_icons.copyright') }}
            </p>

        </div>
    </div>
</x-app-layout>

<script>
function iconPreview() {
    return {
        url: null,
        broken: false,
        update(id) {
            this.broken = false;
            const n = parseInt(id, 10);
            if (n > 0) {
                this.url = `https://lesroyaumes.cdn.oxv.fr/images/items/item${n}.png`;
            } else {
                this.url = null;
            }
        }
    };
}
</script>
