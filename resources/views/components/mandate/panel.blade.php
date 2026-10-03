{{-- Panneau d'un geste dans une ligne dépliée. Fond neutre, liseré et titre colorés : la couleur
     désigne le geste sans que tous les panneaux crient au même niveau (retour de Greg, 03/10). --}}
@props(['tone' => 'gray', 'title' => null])
@php($tones = [
    'green' => ['border-l-green-500', 'text-green-300'],
    'red' => ['border-l-red-500', 'text-red-300'],
    'amber' => ['border-l-amber-500', 'text-amber-300'],
    'orange' => ['border-l-orange-500', 'text-orange-300'],
    'blue' => ['border-l-blue-500', 'text-blue-300'],
    'gray' => ['border-l-gray-400', 'text-gray-200'],
])
<div {{ $attributes->merge(['class' => 'rounded-md border border-gray-700 border-l-4 bg-gray-900/30 p-3 space-y-2 '.$tones[$tone][0]]) }}>
    @if($title)<p class="text-sm font-semibold {{ $tones[$tone][1] }}">{{ $title }}</p>@endif
    {{ $slot }}
</div>
