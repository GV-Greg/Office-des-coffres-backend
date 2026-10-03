{{-- Statut effectif en pastille (texte de _status, couleur en redondance). --}}
@php($tone = ['pending' => 'gray', 'elected' => 'blue', 'active' => 'green', 'extended' => 'amber', 'expired' => 'gray', 'approved' => 'green', 'rejected' => 'red', 'revoked' => 'red'][$mandate->effectiveStatus()] ?? 'gray')
<x-mandate.chip :tone="$tone">@include('mandates._status', ['mandate' => $mandate])</x-mandate.chip>
