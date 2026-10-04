{{--
    Version texte de chaque email (lue par les messageries sans HTML et par les filtres) : même
    contenu que la version HTML. L'original de Laravel affichait app.url (le panneau d'administration)
    en tête et « Tous droits réservés » en pied (05/10/2026).
--}}
<x-mail::layout>
    <x-slot:header>
        <x-mail::header :url="config('app.frontend_url')">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{ $slot }}

    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    <x-slot:footer>
        <x-mail::footer>
{{ __('mail.unofficial', [], 'fr') }}
{{ __('mail.unofficial', [], 'en') }}
{{ config('app.frontend_url') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
