{{--
    Gabarit des notifications de l'Office (publié depuis Laravel le 04/10/2026, habillé selon
    CHARTE-GRAPHIQUE.md). Écarts avec l'original, tous voulus :
    - aucun titre par défaut (« Bonjour ! ») : nos emails portent leur propre salutation, dans
      chaque langue — l'original en ajoutait une seconde, en tête ;
    - la ligne « ——— » qui sépare le français de l'anglais devient un filet ;
    - la signature garde ses retours à la ligne ;
    - marqueurs %%button%% / %%button2:TEXT%% dans introLines : placent le bouton FR (actionText /
      actionUrl) ou EN (TEXT + actionUrl — l'URL est toujours la même pour les deux langues) au
      bon endroit dans les emails bilingues. Après chaque bouton, la salutation de la langue et le
      subcopy (« Le bouton ne s'ouvre pas ? ») sont insérés automatiquement. Sans marqueur, le
      bouton, la salutation et le subcopy restent en bas (compatibilité avec les emails non
      bilingues, ex. MandateVerificationDigest).
    - marqueur %%panel:CHAR:POST:COLOR:TEXT%% : encadré d'identité (fond orange clair, liseré orange)
      avec nom en gras, poste et badge optionnel (COLOR = approved/rejected, TEXT = libellé traduit).
    - marqueurs %%panel_start%% / %%panel_end%% : encadré générique (conservé pour compatibilité).
    - marqueur %%badge:COLOR:TEXT%% : badge standalone (COLOR = approved/rejected, TEXT = libellé).
    - note : SimpleMessage::with() traite les tableaux comme des lignes de texte (outroLines) et ne
      stocke pas dans viewData — les données EN sont donc encodées dans le marqueur %%button2:TEXT%%.
--}}
@php
    // Un email est « bilingue » s'il a un bouton FR inline (%%button%%) OU un bouton EN
    // (%%button2:*%%) — dans ce cas les salutations et subcopies se placent après chaque bouton.
    $hasInlineButtons = in_array('%%button%%', $introLines ?? [])
        || collect($introLines ?? [])->contains(fn($l) => str_starts_with((string) $l, '%%button2'));

    // Pour les emails bilingues : scinder la salutation en partie FR et EN (séparées par \n\n).
    if ($hasInlineButtons && !empty($salutation)) {
        $salParts = explode("\n\n", $salutation, 2);
        $salFr = $salParts[0] ?? $salutation;
        $salEn = $salParts[1] ?? '';
    } else {
        $salFr = $salEn = '';
    }

    $badgeStyles = [
        'approved' => 'display:inline-block;padding:2px 10px;border-radius:4px;background:#dcfce7;color:#166534;font-weight:700;font-size:13px;',
        'rejected' => 'display:inline-block;padding:2px 10px;border-radius:4px;background:#fee2e2;color:#991b1b;font-weight:700;font-size:13px;',
    ];
@endphp
<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
@if ($line === '———')
<hr class="lang-separator">
@elseif ($line === '%%button%%')
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset
@if ($hasInlineButtons && !empty($salFr))
<p class="salutation">{!! nl2br(e($salFr)) !!}</p>
@endif
@if ($hasInlineButtons && isset($actionUrl))
<p style="margin-top:4px;padding-top:0;border-top:none;font-size:12px;font-style:italic;color:#475569;">{{ __('mail.subcopy', [], 'fr') }}<br><span class="break-all"><a href="{{ $actionUrl }}">{{ $displayableActionUrl }}</a></span></p>
@endif
@elseif (str_starts_with((string) $line, '%%button2'))
@php
    // %%button2:TEXT%% — TEXT = texte du bouton EN encodé dans le marqueur ;
    // l'URL EN est toujours identique à l'URL FR ($actionUrl).
    $btn2Text = str_starts_with((string) $line, '%%button2:')
        ? trim(substr((string) $line, 10), '%')
        : null;
@endphp
@if (!empty($btn2Text))
<x-mail::button :url="$actionUrl" color="primary">
{{ $btn2Text }}
</x-mail::button>
@endif
@if ($hasInlineButtons && !empty($salEn))
<p class="salutation">{!! nl2br(e($salEn)) !!}</p>
@endif
@if ($hasInlineButtons && !empty($actionUrl))
<p style="margin-top:4px;padding-top:0;border-top:none;font-size:12px;font-style:italic;color:#475569;">{{ __('mail.subcopy', [], 'en') }}<br><span class="break-all"><a href="{{ $actionUrl }}">{{ $displayableActionUrl }}</a></span></p>
@endif
@elseif (str_starts_with((string) $line, '%%panel:'))
@php
    [$panelChar, $panelPost, $panelColor, $panelBadge] = array_pad(
        explode(':', trim(substr((string) $line, 8), '%'), 4), 4, ''
    );
    $panelBadgeStyle = $badgeStyles[$panelColor] ?? null;
@endphp
<div style="border-left:4px solid #c2410c;padding:12px 16px;background:#fff7ed;margin:16px 0;border-radius:4px;">
<p style="margin:0 0 2px;font-weight:700;font-size:16px;color:#1e293b;">{{ $panelChar }}</p>
<p style="margin:0;font-size:14px;color:#64748b;">{{ $panelPost }}</p>
@if (!empty($panelBadge) && $panelBadgeStyle)
<p style="margin:8px 0 0;"><span style="{{ $panelBadgeStyle }}">{{ $panelBadge }}</span></p>
@endif
</div>
@elseif ($line === '%%panel_start%%')
<div style="border-left:4px solid #c2410c;padding:12px 16px;background:#fff7ed;margin:16px 0;border-radius:4px;">
@elseif ($line === '%%panel_end%%')
</div>
@elseif (str_starts_with((string) $line, '%%badge:'))
@php
    [$badgeColor, $badgeText] = array_pad(
        explode(':', trim(substr((string) $line, 8), '%'), 2), 2, ''
    );
@endphp
@if (!empty($badgeText) && isset($badgeStyles[$badgeColor]))
<p style="margin:0;"><span style="{{ $badgeStyles[$badgeColor] }}">{{ $badgeText }}</span></p>
@endif
@else
{{ $line }}
@endif

@endforeach

@if (! $hasInlineButtons)
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset
@endif

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (!$hasInlineButtons)
@if (! empty($salutation))
<p class="salutation">{!! nl2br(e($salutation)) !!}</p>
@else
<p class="salutation">@lang('Regards,')<br>{{ config('app.name') }}</p>
@endif
@endif

@if (!$hasInlineButtons)
@isset($actionText)
<x-slot:subcopy>
{{ __('mail.subcopy', [], 'fr') }} / {{ __('mail.subcopy', [], 'en') }}<br>
<span class="break-all"><a href="{{ $actionUrl }}">{{ $displayableActionUrl }}</a></span>
</x-slot:subcopy>
@endisset
@endif
</x-mail::message>
