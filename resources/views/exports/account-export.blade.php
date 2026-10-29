<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 11px;
    color: #1e293b;
    margin: 0;
    padding: 0;
  }
  .header {
    background: #1e293b;
    color: #ffffff;
    padding: 14px 24px;
    border-bottom: 3px solid #c2410c;
  }
  .header-inner {
    display: table;
    width: 100%;
  }
  .header-logo {
    display: table-cell;
    vertical-align: middle;
    width: 160px;
  }
  .header-logo img {
    height: 36px;
    width: auto;
  }
  .header-text {
    display: table-cell;
    vertical-align: middle;
    text-align: right;
  }
  .header-text .subtitle { font-size: 10px; color: #94a3b8; margin-bottom: 2px; }
  .header-text .date     { font-size: 10px; color: #94a3b8; }
  .content { padding: 18px 24px; }
  .section { margin-bottom: 18px; }
  .section-title {
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #c2410c;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 3px;
    margin-bottom: 8px;
  }
  .row { margin-bottom: 3px; }
  .label { color: #64748b; }
  .item { margin-bottom: 10px; padding-left: 10px; }
  .item-title { font-weight: bold; margin-bottom: 3px; }
  .not-included { font-size: 9.5px; color: #475569; }
  .page-break { page-break-before: always; }
  .disclaimer {
    font-size: 8.5px;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding-top: 8px;
    margin-top: 20px;
    text-align: center;
  }
</style>
</head>
<body>

@php
  $kingdomsFr = [
    'Kingdom of France'                  => 'Royaume de France',
    'Holy Roman German Empire'           => 'Saint Empire Romain Germanique',
    'Kingdom of England'                 => "Royaume d'Angleterre",
    'Kingdom of Scotland'                => "Royaume d'Écosse",
    'Corona de Aragón'                   => "Couronne d'Aragon",
    'Corona de Castilla y León'          => 'Couronne de Castille et Léon',
    'Regno delle Due Sicilie'            => 'Royaume des Deux-Siciles',
    'Reino de Portugal'                  => 'Royaume du Portugal',
    'Ireland'                            => 'Irlande',
    'Serenissima Repubblica di Venezia'  => 'Sérénissime République de Venise',
    'Kalmar Union'                       => 'Union de Kalmar (Danemark, Finlande, Suède)',
  ];
  $statusesFr = [
    'pending'  => 'En attente',
    'elected'  => 'Élu',
    'active'   => 'En fonction',
    'extended' => 'En prolongation',
    'expired'  => 'Expiré',
    'rejected' => 'Refusé',
    'revoked'  => 'Révoqué',
  ];
  $statusesEn = [
    'pending'  => 'Pending',
    'elected'  => 'Elected',
    'active'   => 'Active',
    'extended' => 'Extended',
    'expired'  => 'Expired',
    'rejected' => 'Rejected',
    'revoked'  => 'Revoked',
  ];
@endphp

{{-- ===================================================== --}}
{{--  PAGE 1 — FRANÇAIS                                    --}}
{{-- ===================================================== --}}

<div class="header">
  <div class="header-inner">
    <div class="header-logo">
      <img src="data:image/png;base64,{{ $logoBase64 }}" alt="Office des Coffres">
    </div>
    <div class="header-text">
      <div class="subtitle">Export de vos données personnelles</div>
      <div class="date">Généré le : {{ $generatedAt }}</div>
    </div>
  </div>
</div>

<div class="content">

  <div class="section">
    <div class="section-title">Compte</div>
    <div class="row"><span class="label">Email :</span> {{ $account['email'] }}</div>
    <div class="row"><span class="label">Vérifié le :</span>
      {{ $account['email_verified_at']
          ? \Carbon\Carbon::parse($account['email_verified_at'])->setTimezone('Europe/Paris')->format('d/m/Y')
          : '—' }}
    </div>
    <div class="row"><span class="label">Inscrit le :</span>
      {{ \Carbon\Carbon::parse($account['created_at'])->setTimezone('Europe/Paris')->format('d/m/Y') }}
    </div>
    @if(count($account['roles']) > 0)
    <div class="row"><span class="label">Rôles :</span> {{ implode(', ', $account['roles']) }}</div>
    @endif
  </div>

  <div class="section">
    <div class="section-title">Personnages</div>
    @forelse($characters as $character)
    <div class="item">
      <div class="item-title">▸ {{ $character['pseudo'] }}</div>
      <div class="row"><span class="label">Résidence :</span>
        @if($character['residence']['city'])
          {{ $character['residence']['city'] }},
          {{ $character['residence']['province'] }},
          {{ $kingdomsFr[$character['residence']['kingdom']] ?? $character['residence']['kingdom'] }}
        @else
          —
        @endif
      </div>
      <div class="row"><span class="label">Validé :</span>
        {{ $character['is_validated'] ? 'oui' : 'non' }}
      </div>
    </div>
    @empty
    <div class="row">Aucun personnage enregistré.</div>
    @endforelse
  </div>

  @if(count($mandates) > 0)
  <div class="section">
    <div class="section-title">Postes déclarés</div>
    @foreach($mandates as $mandate)
    <div class="item">
      <div class="item-title">▸
        @if($mandate['level'] === 'mayor')
          Maire de {{ $mandate['place']['city'] ?? '—' }}
        @else
          Conseiller comtal — {{ $mandate['place']['province'] ?? '—' }}@if($mandate['title']) ({{ $mandate['title'] }})@endif
        @endif
      </div>
      @if($mandate['started_at'])
      <div class="row"><span class="label">Début :</span>
        {{ \Carbon\Carbon::parse($mandate['started_at'])->format('d/m/Y') }}
      </div>
      @endif
      @if($mandate['holds_until'])
      <div class="row"><span class="label">Fin effective :</span>
        {{ \Carbon\Carbon::parse($mandate['holds_until'])->setTimezone('Europe/Paris')->format('d/m/Y') }}
      </div>
      @endif
      <div class="row"><span class="label">Statut :</span>
        {{ $statusesFr[$mandate['status']] ?? $mandate['status'] }}
      </div>
      @if($mandate['announcement_url'])
      <div class="row"><span class="label">Annonce :</span> {{ $mandate['announcement_url'] }}</div>
      @endif
    </div>
    @endforeach
  </div>
  @endif

  <div class="section">
    <div class="section-title">Non inclus dans cet export</div>
    <div class="not-included">
      <div class="row">{{ $notIncludedFr['browser_storage'] }}</div>
      <div class="row" style="margin-top: 5px;">{{ $notIncludedFr['credentials'] }}</div>
    </div>
  </div>

  <div class="disclaimer">
    Office des Coffres — outil communautaire non officiel, non affilié à Celsius Online / Renaissance Kingdoms —
    https://officedescoffres.creacube.be
  </div>

</div>

{{-- ===================================================== --}}
{{--  PAGE 2 — ENGLISH                                     --}}
{{-- ===================================================== --}}

<div class="page-break">

<div class="header">
  <div class="header-inner">
    <div class="header-logo">
      <img src="data:image/png;base64,{{ $logoBase64 }}" alt="Office des Coffres">
    </div>
    <div class="header-text">
      <div class="subtitle">Personal data export</div>
      <div class="date">Generated on: {{ $generatedAtEn }}</div>
    </div>
  </div>
</div>

<div class="content">

  <div class="section">
    <div class="section-title">Account</div>
    <div class="row"><span class="label">Email:</span> {{ $account['email'] }}</div>
    <div class="row"><span class="label">Verified on:</span>
      {{ $account['email_verified_at']
          ? \Carbon\Carbon::parse($account['email_verified_at'])->setTimezone('Europe/Paris')->format('d F Y')
          : '—' }}
    </div>
    <div class="row"><span class="label">Registered on:</span>
      {{ \Carbon\Carbon::parse($account['created_at'])->setTimezone('Europe/Paris')->format('d F Y') }}
    </div>
    @if(count($account['roles']) > 0)
    <div class="row"><span class="label">Roles:</span> {{ implode(', ', $account['roles']) }}</div>
    @endif
  </div>

  <div class="section">
    <div class="section-title">Characters</div>
    @forelse($characters as $character)
    <div class="item">
      <div class="item-title">▸ {{ $character['pseudo'] }}</div>
      <div class="row"><span class="label">Residence:</span>
        @if($character['residence']['city'])
          {{ $character['residence']['city'] }},
          {{ $character['residence']['province'] }},
          {{ $character['residence']['kingdom'] }}
        @else
          —
        @endif
      </div>
      <div class="row"><span class="label">Validated:</span>
        {{ $character['is_validated'] ? 'yes' : 'no' }}
      </div>
    </div>
    @empty
    <div class="row">No characters registered.</div>
    @endforelse
  </div>

  @if(count($mandates) > 0)
  <div class="section">
    <div class="section-title">Declared positions</div>
    @foreach($mandates as $mandate)
    <div class="item">
      <div class="item-title">▸
        @if($mandate['level'] === 'mayor')
          Mayor of {{ $mandate['place']['city'] ?? '—' }}
        @else
          County council — {{ $mandate['place']['province'] ?? '—' }}@if($mandate['title']) ({{ $mandate['title'] }})@endif
        @endif
      </div>
      @if($mandate['started_at'])
      <div class="row"><span class="label">Start:</span>
        {{ \Carbon\Carbon::parse($mandate['started_at'])->format('d F Y') }}
      </div>
      @endif
      @if($mandate['holds_until'])
      <div class="row"><span class="label">Effective end:</span>
        {{ \Carbon\Carbon::parse($mandate['holds_until'])->setTimezone('Europe/Paris')->format('d F Y') }}
      </div>
      @endif
      <div class="row"><span class="label">Status:</span>
        {{ $statusesEn[$mandate['status']] ?? $mandate['status'] }}
      </div>
      @if($mandate['announcement_url'])
      <div class="row"><span class="label">Announcement:</span> {{ $mandate['announcement_url'] }}</div>
      @endif
    </div>
    @endforeach
  </div>
  @endif

  <div class="section">
    <div class="section-title">Not included in this export</div>
    <div class="not-included">
      <div class="row">{{ $notIncludedEn['browser_storage'] }}</div>
      <div class="row" style="margin-top: 5px;">{{ $notIncludedEn['credentials'] }}</div>
    </div>
  </div>

  <div class="disclaimer">
    Office des Coffres — unofficial community tool, not affiliated with Celsius Online / Renaissance Kingdoms —
    https://officedescoffres.creacube.be
  </div>

</div>
</div>

</body>
</html>
