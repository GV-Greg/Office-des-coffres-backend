@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'form-field dark:dark-form-field focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50 dark:focus:border-blue-600 focus:ring-blue-500 dark:focus:ring-blue-600']) !!}>
