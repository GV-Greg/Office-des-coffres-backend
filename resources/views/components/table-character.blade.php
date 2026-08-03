@props(['character'])

<tr class="border-b border-gray-100 dark:border-gray-600">
    <td class="py-2 hidden md:table-cell">{{ $character->id }}</td>
    <td class="py-2 font-bold">{{ $character->pseudo }}</td>
    <td class="py-2 hidden md:table-cell">{{ $character->user->email }}</td>
    <td class="py-2 hidden md:table-cell">
        @if($character->city)
            {{ $character->city->province->kingdom->kingdom_name }}
        @endif
    </td>
    <td class="py-2 hidden md:table-cell">
        @if($character->city)
            {{ $character->city->province->province_name }}
        @endif
    </td>
    <td class="py-2 hidden md:table-cell">
        @if($character->city)
            {{ $character->city->city_name }}
        @endif
    </td>
    <td class="py-2 hidden md:table-cell">{{ $character->created_at->format('j M Y') }}</td>
    <td class="py-2 text-center">
        @if($character->is_validated)
            <span class="px-2 py-1 rounded bg-green-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                <i class="fa-solid fa-circle-check"></i>
                {{ __('Validated') }}
            </span>
        @else
            <span class="px-2 py-1 rounded bg-red-500 text-white text-xs uppercase font-bold whitespace-nowrap">
                <i class="fa-solid fa-clock"></i>
                {{ __('Not validated') }}
            </span>
        @endif
    </td>
    <td class="py-2 text-right">
        <form method="POST" action="{{ route('characters.validate', $character) }}">
            @csrf
            @method('PATCH')
            @if($character->is_validated)
                <button type="button"
                        onclick="confirmDangerousAction(this, '{{ __('Invalidate this character?') }}', '#f97316')"
                        data-confirm-text="{{ __('Yes') }}" data-cancel-text="{{ __('Cancel') }}"
                        class="px-2 py-1 rounded bg-orange-500 hover:bg-orange-600 text-white text-xs uppercase font-bold whitespace-nowrap">
                    <i class="fa-solid fa-rotate-left"></i>
                    {{ __('Invalidate') }}
                </button>
            @else
                <button type="button"
                        onclick="confirmDangerousAction(this, '{{ __('Validate this character?') }}', '#16a34a')"
                        data-confirm-text="{{ __('Yes') }}" data-cancel-text="{{ __('Cancel') }}"
                        class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs uppercase font-bold whitespace-nowrap">
                    <i class="fa-solid fa-check"></i>
                    {{ __('Validate') }}
                </button>
            @endif
        </form>
    </td>
</tr>
