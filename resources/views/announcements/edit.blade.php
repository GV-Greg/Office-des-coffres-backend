<x-app-layout>
    <x-slot name="header">
        {{ __('announcements.title') }}
    </x-slot>

    <div class="pl-14 py-6">
        <div class="w-3/4 mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
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
                    <h2 class="font-bold mb-4">{{ __('announcements.edit_heading') }}</h2>
                    @include('announcements._form', ['announcement' => $announcement, 'action' => route('announcements.update', $announcement), 'method' => 'PUT'])
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
