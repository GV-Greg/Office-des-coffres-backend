@props(['character'])

<tr>
    <th class="hidden md:table-cell">{{ $character->id }}</th>
    <td class="font-bold">{{ $character->Character->pseudo }}</td>
    <td class="hidden md:table-cell">
        @if(isset($character->Character->city))
            {{ $character->Character->city->province->kingdom->kingdom_name }}
        @endif
    </td>
    <td class="hidden md:table-cell">
        @if(isset($character->Character->city))
            {{ $character->Character->city->province->province_name }}
        @endif
    </td>
    <td class="hidden md:table-cell">
        @if(isset($character->Character->city))
            {{ $character->Character->city->city_name }}
        @endif
    </td>
    <td class="hidden md:table-cell">{{ $character->created_at->format('j M Y - H:m') }}</td>
    <td class="text-center">
        @if($character->Character->is_validated)
            <a href="#" class="ml-3 px-2 py-1 rounded bg-green-500 text-white uppercase font-bold">{{ __('Validated') }}</a>
            {{--                                    <a href="{{ route('player.change.status',$player->id) }}" class="ml-3 px-2 py-1 rounded bg-green-500 text-white uppercase font-bold">{{ __('Validated') }}</a>--}}
        @else
            <a href="#" class="ml-3 px-2 py-1 rounded bg-red-500 text-white uppercase font-bold">{{ __('Not validated') }}</a>
            {{--                                    <a href="{{ route('player.change.status',$player->id) }}" class="ml-3 px-2 py-1 rounded bg-red-500 text-white uppercase font-bold">{{ __('Not validated') }}</a>--}}
        @endif
    </td>
    <td class="text-center">
        {{--                                <a class="btn-show" href="{{ route('player.show',$player->id) }}"><i class="fa fa-fw fa-eye"></i><span class="hidden md:inline-block md:ml-1">{{ __('Show') }}</span></a>--}}
        {{--                                <a class="btn-edit" href="{{ route('player.edit',$player->id) }}"><i class="fa fa-fw fa-edit"></i><span class="hidden md:inline-block md:ml-1">{{ __('Edit') }}</span></a>--}}
        {{--                                <form action="{{ route('player.destroy',$player->id) }}" method="POST" class="hidden md:inline-block md:ml-1">--}}
        {{--                                    @csrf--}}
        {{--                                    {{ method_field('DELETE') }}--}}
        {{--                                    <button class="btn-delete"><i class="fa fa-fw fa-trash"></i><span>{{ __('Delete') }}</span></button>--}}
        {{--                                </form>--}}
    </td>
</tr>
