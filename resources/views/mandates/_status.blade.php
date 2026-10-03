{{-- Statut effectif d'un mandat, en toutes lettres (le libellé porte l'information, pas la couleur). --}}
@php($status = $mandate->effectiveStatus())
{{ __('mandates.admin.statuses.'.$status, [
    'date' => \App\Support\MandateLabels::date($status === 'extended' ? $mandate->holds_until : $mandate->valid_until),
]) }}
