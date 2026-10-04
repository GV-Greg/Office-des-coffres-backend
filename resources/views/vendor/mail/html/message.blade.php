<x-mail::layout>
{{-- En-tête : le logo, lien vers le site des joueurs (pas vers app.url, qui est le panneau admin). --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url')">
{{-- Logo servi par le site des joueurs (front, public/images/email) : jamais par le domaine de
     l'administration, qui ferait passer l'email pour de l'hameçonnage (Greg, 05/10/2026). --}}
<img src="{{ rtrim((string) config('app.frontend_url'), '/') }}/images/email/logo-horizontal.png" class="logo" width="240" height="76" alt="{{ config('app.name') }}">
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Pied : « outil non officiel » toujours visible (charte §1.7), dans les deux langues. --}}
<x-slot:footer>
<x-mail::footer>
{{ __('mail.unofficial', [], 'fr') }}<br>
{{ __('mail.unofficial', [], 'en') }}

[{{ preg_replace('#^https?://#', '', rtrim((string) config('app.frontend_url'), '/')) }}]({{ config('app.frontend_url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
