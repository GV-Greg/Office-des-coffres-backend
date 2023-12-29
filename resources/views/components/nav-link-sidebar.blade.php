@props(['active'])

@php
$classes = ($active ?? false)
            ? 'px-1 py-5 no-underline bg-blue-600 hover:bg-blue-400 font-semibold text-xl text-white hover:text-gray-800 flex justify-center items-center border-r-4 border-orange-600 divide-x-orange-600 leading-5 focus:outline-none focus:border-orange-700 transition duration-150 ease-in-out'
            : 'px-1 py-5 no-underline bg-blue-600 hover:bg-blue-400 font-semibold text-xl text-white hover:text-gray-800 flex justify-center items-center border-r-4 border-gray-600 leading-5 hover:text-gray-700 focus:outline-none focus:text-gray-700 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
