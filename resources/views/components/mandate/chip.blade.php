{{-- Pastille d'alerte ou de statut. Le texte porte l'information, la couleur la redouble. --}}
@props(['tone' => 'gray'])
@php($tones = [
    'red' => 'bg-red-900/60 text-red-100 ring-red-400/40',
    'amber' => 'bg-amber-900/60 text-amber-100 ring-amber-400/40',
    'blue' => 'bg-blue-900/60 text-blue-100 ring-blue-400/40',
    'green' => 'bg-green-900/60 text-green-100 ring-green-400/40',
    'gray' => 'bg-gray-700 text-gray-100 ring-gray-400/40',
])
<span {{ $attributes->merge(['class' => 'text-[11px] font-semibold px-2 py-0.5 rounded-full ring-1 '.$tones[$tone]]) }}>{{ $slot }}</span>
