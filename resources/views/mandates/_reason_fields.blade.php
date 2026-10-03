{{-- Motif codé + commentaire étiqueté par sa langue (Q7). $reasons : liste des codes autorisés.
     Motif et langue sur une ligne, commentaire en pleine largeur dessous. --}}
<div class="space-y-2">
    <div class="flex flex-wrap gap-3 items-end">
        <label class="text-xs text-gray-300 grow min-w-[14rem]">
            <span class="block mb-0.5">{{ __('mandates.admin.reason') }}</span>
            <select name="reason" required class="w-full rounded border-gray-600 bg-gray-800 text-gray-100 text-sm">
                @foreach($reasons as $code)
                    <option value="{{ $code }}">{{ \App\Support\MandateLabels::reason($code, 'fr') }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs text-gray-300">
            <span class="block mb-0.5">{{ __('mandates.admin.comment_locale') }}</span>
            <select name="locale" class="rounded border-gray-600 bg-gray-800 text-gray-100 text-sm">
                <option value="fr">{{ __('mandates.admin.locale_fr') }}</option>
                <option value="en">{{ __('mandates.admin.locale_en') }}</option>
            </select>
        </label>
    </div>
    <label class="text-xs text-gray-300 block">
        <span class="block mb-0.5">{{ __('mandates.admin.comment') }}</span>
        <textarea name="message" rows="2" class="w-full rounded border-gray-600 bg-gray-800 text-gray-100 text-sm"></textarea>
    </label>
    <p class="text-xs text-gray-400">{{ __('mandates.admin.comment_warning') }}</p>
</div>
