{{-- Formulaire d'annonce (création et modification). Heures saisies à Paris. --}}
@php
    use App\Models\SiteAnnouncement as A;
    $type = old('schedule_type', $announcement->schedule_type ?? '');
    $field = fn (string $name, $default = '') => old($name, $default);
    $exactStart = $announcement->schedule_type === A::EXACT ? A::toParisInput($announcement->maintenance_starts_at) : '';
    $exactEnd = $announcement->schedule_type === A::EXACT ? A::toParisInput($announcement->maintenance_ends_at) : '';
    $periodFrom = $announcement->schedule_type === A::PERIOD ? A::toParisInput($announcement->maintenance_starts_at, A::DATE_FORMAT) : '';
    $periodTo = $announcement->schedule_type === A::PERIOD ? A::toParisInput($announcement->maintenance_ends_at, A::DATE_FORMAT) : '';
    $input = 'w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5" x-data="{ type: @js($type) }">
    @csrf
    @if(! empty($method)) @method($method) @endif

    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('announcements.help') }}</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="message_fr" class="block text-sm font-medium mb-1">{{ __('announcements.message_fr') }} <span aria-hidden="true">*</span></label>
            <textarea id="message_fr" name="message_fr" rows="3" required maxlength="{{ A::MESSAGE_MAX }}" class="{{ $input }}">{{ $field('message_fr', $announcement->message_fr) }}</textarea>
        </div>
        <div>
            <label for="message_en" class="block text-sm font-medium mb-1">{{ __('announcements.message_en') }} <span aria-hidden="true">*</span></label>
            <textarea id="message_en" name="message_en" rows="3" required maxlength="{{ A::MESSAGE_MAX }}" class="{{ $input }}">{{ $field('message_en', $announcement->message_en) }}</textarea>
        </div>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('announcements.message_help') }}</p>

    <fieldset class="space-y-3">
        <legend class="text-sm font-medium mb-1">{{ __('announcements.schedule') }}</legend>
        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <label class="inline-flex items-center gap-2"><input type="radio" name="schedule_type" value="" x-model="type" @checked($type === '')> {{ __('announcements.schedule_none') }}</label>
            <label class="inline-flex items-center gap-2"><input type="radio" name="schedule_type" value="exact" x-model="type" @checked($type === 'exact')> {{ __('announcements.schedule_exact') }}</label>
            <label class="inline-flex items-center gap-2"><input type="radio" name="schedule_type" value="period" x-model="type" @checked($type === 'period')> {{ __('announcements.schedule_period') }}</label>
        </div>

        <div x-show="type === 'exact'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="exact_starts_at" class="block text-sm mb-1">{{ __('announcements.exact_starts_at') }}</label>
                <input type="datetime-local" id="exact_starts_at" name="exact_starts_at" value="{{ $field('exact_starts_at', $exactStart) }}" class="{{ $input }}">
            </div>
            <div>
                <label for="exact_ends_at" class="block text-sm mb-1">{{ __('announcements.exact_ends_at') }}</label>
                <input type="datetime-local" id="exact_ends_at" name="exact_ends_at" value="{{ $field('exact_ends_at', $exactEnd) }}" class="{{ $input }}">
            </div>
        </div>

        <div x-show="type === 'period'" x-cloak class="space-y-2">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="period_from" class="block text-sm mb-1">{{ __('announcements.period_from') }}</label>
                    <input type="date" id="period_from" name="period_from" value="{{ $field('period_from', $periodFrom) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="period_to" class="block text-sm mb-1">{{ __('announcements.period_to') }}</label>
                    <input type="date" id="period_to" name="period_to" value="{{ $field('period_to', $periodTo) }}" class="{{ $input }}">
                </div>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('announcements.period_help') }}</p>
        </div>
    </fieldset>

    <fieldset class="space-y-3">
        <legend class="text-sm font-medium mb-1">{{ __('announcements.visibility') }}</legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="visible_from" class="block text-sm mb-1">{{ __('announcements.visible_from') }}</label>
                <input type="datetime-local" id="visible_from" name="visible_from" value="{{ $field('visible_from', $announcement->exists ? A::toParisInput($announcement->visible_from) : '') }}" class="{{ $input }}">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('announcements.visible_from_help') }}</p>
            </div>
            <div>
                <label for="visible_until" class="block text-sm mb-1">{{ __('announcements.visible_until') }}</label>
                <input type="datetime-local" id="visible_until" name="visible_until" value="{{ $field('visible_until', A::toParisInput($announcement->visible_until)) }}" class="{{ $input }}">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('announcements.visible_until_help') }}</p>
            </div>
        </div>
    </fieldset>

    <div class="flex items-center gap-3">
        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded">{{ __('announcements.save') }}</button>
        @if($announcement->exists)
            <a href="{{ route('announcements.index') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-100 text-sm rounded">{{ __('announcements.cancel') }}</a>
        @endif
    </div>
</form>
