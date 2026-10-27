<?php

use App\Models\AnimActivityType;
use App\Services\DecodeExporter;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;

/*
| Export BBCode (français fixe) : règles en corps RP puis [hrp] avec le lien ; résultats selon que le
| code est en cours ou trouvé.
*/

beforeEach(function () {
    config(['app.frontend_url' => 'https://site.example']);
    $map = mandateMap();
    $this->orga = mandatePlayer($map['cityA']);
    $this->workflow = app(DecodeWorkflow::class);
    $this->activity = $this->workflow->create($this->orga, AnimActivityType::where('key', 'decode')->first(), [
        'name' => 'Coffre des Marches', 'slug' => 'coffre-des-marches', 'province_id' => $map['provinceA']->id,
    ]);
    $this->secret = $this->workflow->generateSecret($this->orga, $this->activity);
    $this->exporter = app(DecodeExporter::class);
});

test('les règles : corps RP, puis [hrp] avec le lien vers la page publique', function () {
    $bb = $this->exporter->exportRules($this->activity);

    expect($bb)->toStartWith('[b]Coffre des Marches[/b]')
        ->toContain('Organisateur : '.$this->orga->pseudo)
        ->toContain('Lieu : Artois')
        ->toContain('combinaison secrète de cinq chiffres')
        ->toEndWith('[hrp][url=https://site.example/public/activite/decode/coffre-des-marches]Coffre n°1 à percer[/url][/hrp]')
        // Le texte des règles passe avant le bloc hors-RP, et la description n'y figure pas.
        ->and(strpos($bb, 'combinaison'))->toBeLessThan(strpos($bb, '[hrp]'));
});

test('les résultats d\'un code en cours : tentatives seulement', function () {
    app(DecodeProposals::class)->submit($this->orga, $this->activity, $this->secret, '00000', 'Aldric');

    $bb = $this->exporter->exportResults($this->activity->refresh());

    expect($bb)->toContain('Coffre n°1 — tentatives')
        ->toContain('00000 — Aldric')
        ->not->toContain('Coffres ouverts')
        ->not->toContain('Classement cumulé')
        ->not->toContain($this->secret->code === '00000' ? 'impossible' : $this->secret->code)
        ->toContain('[hrp][url=https://site.example/public/activite/decode/coffre-des-marches]Résultats complets[/url][/hrp]');
});

test('les résultats d\'un code trouvé : combinaison révélée, découvreur, coffres ouverts, classement cumulé', function () {
    $proposals = app(DecodeProposals::class);
    $proposals->submit($this->orga, $this->activity, $this->secret, '00000', 'Aldric');
    $proposals->submit($this->orga, $this->activity, $this->secret, $this->secret->code, 'Brunehaut');

    $bb = $this->exporter->exportResults($this->activity->refresh());

    expect($bb)->toContain('Le coffre n°1 est ouvert')
        ->toContain($this->secret->code)
        ->toContain('trouvée par Brunehaut')
        ->toContain('Coffres ouverts')
        ->toContain('Coffre n°1 : '.$this->secret->code.' — Brunehaut')
        ->toContain('Classement cumulé')
        ->toContain('1. Brunehaut — 20 pt(s)')
        // Le nouveau coffre, lui, reste scellé.
        ->toContain('Coffre n°2 — tentatives');
});
