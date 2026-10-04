{{--
    Gabarit des notifications de l'Office (publié depuis Laravel le 04/10/2026, habillé selon
    CHARTE-GRAPHIQUE.md). Écarts avec l'original, tous voulus :
    - aucun titre par défaut (« Bonjour ! ») : nos emails portent leur propre salutation, dans
      chaque langue — l'original en ajoutait une seconde, en tête ;
    - la ligne « ——— » qui sépare le français de l'anglais devient un filet ;
    - la signature garde ses retours à la ligne ;
    - la ligne d'aide sous le bouton est bilingue (lang/{fr,en}/mail.php).
--}}
<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
@if ($line === '———')
<hr class="lang-separator">
@else
{{ $line }}
@endif

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
<p class="salutation">{!! nl2br(e($salutation)) !!}</p>
@else
<p class="salutation">@lang('Regards,')<br>{{ config('app.name') }}</p>
@endif

@isset($actionText)
<x-slot:subcopy>
{{ __('mail.subcopy', [], 'fr') }} / {{ __('mail.subcopy', [], 'en') }}<br>
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
