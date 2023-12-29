@props(['value'])

<label {{ $attributes->merge(['class' => 'block form-label']) }}>
    {{ $value ?? $slot }}
</label>
