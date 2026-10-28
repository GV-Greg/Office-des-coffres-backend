{{-- Animation — étape de confirmation avant de retirer une activité sans compte (D13). Aucun texte en dur. --}}
<x-app-layout>
    <x-slot name="header">
        {{ __('anim.admin.delete_title', ['name' => $activity->name]) }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-700 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100 space-y-4" role="alertdialog" aria-labelledby="delete-title">
                    <h2 id="delete-title" class="font-bold">{{ __('anim.admin.delete_title', ['name' => $activity->name]) }}</h2>
                    <p class="text-sm">{{ __('anim.admin.delete_warning', ['slug' => $activity->slug]) }}</p>

                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('anim.activities.destroy', $activity) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1 rounded bg-red-700 hover:bg-red-800 text-white text-xs uppercase font-bold">
                                {{ __('anim.admin.delete_confirm') }}
                            </button>
                        </form>
                        <a href="{{ route('anim.index') }}" class="px-3 py-1 rounded bg-gray-500 hover:bg-gray-600 text-white text-xs uppercase font-bold">
                            {{ __('anim.admin.delete_cancel') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
