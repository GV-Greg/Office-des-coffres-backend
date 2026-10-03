<?php

use App\Notifications\MandateDecision;
use App\Support\MandateLabels;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

// Langue des emails des mandats : toujours bilingues, français d'abord puis anglais (Greg,
// 03/10/2026). lang/en/mandates.php ne porte que ce qui part chez le joueur (Q5).
//
// 🔴 __() renvoie la CLÉ BRUTE quand une traduction manque : sans ce garde-fou, un joueur
// anglophone recevrait « mandates.reasons.date_incoherente » dans son email.

beforeEach(function () {
    Notification::fake();
    atGameTime('2026-05-10 12:00');
    $this->map = mandateMap();
});

afterEach(fn () => Carbon::setTestNow());

/** Les parties du fichier de langue qui partent chez le joueur. */
function playerFacingKeys(string $locale): array
{
    $file = require lang_path("{$locale}/mandates.php");

    return collect(Arr::dot([
        'offices' => $file['offices'], 'reasons' => $file['reasons'], 'email' => $file['email'],
        'post' => $file['post'], 'no_office' => $file['no_office'], 'api' => $file['api'],
    ]))->keys()->sort()->values()->all();
}

test('chaque clé envoyée au joueur existe en français ET en anglais', function () {
    expect(playerFacingKeys('en'))->toBe(playerFacingKeys('fr'));
});

test('chaque type d\'email se rend dans les deux langues sans aucune clé brute', function (string $type) {
    $mandate = approvedCouncil(mandatePlayer($this->map['cityA']), $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');

    $notification = new MandateDecision($type, $mandate, [
        'date' => Carbon::now(), 'reason' => 'autre', 'old_reason' => 'retranchement', 'office' => 'capitaine',
        'message' => 'Commentaire', 'locale' => 'en', 'elected' => true, 'title_dropped' => true, 'can_reapply' => true,
    ]);
    $text = implode("\n", $notification->lines());
    $mail = $notification->toMail($mandate->character->user);

    expect($text)->not->toContain('mandates.')
        ->and($mail->subject)->not->toContain('mandates.')
        ->and($mail->actionText)->not->toContain('mandates.')
        ->and($mail->salutation)->not->toContain('mandates.');
})->with(MandateDecision::TYPES);

test('contrôle positif : une clé anglaise manquante fait apparaître la clé brute', function () {
    $mandate = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    app('translator')->addLines(['mandates.email.revoked' => null], 'en');
    app('translator')->setLoaded([]); // oublie le fichier anglais chargé
    $translations = require lang_path('en/mandates.php');
    unset($translations['email']['revoked']);
    app('translator')->addLines(Arr::dot($translations, 'mandates.'), 'en');

    $text = implode("\n", (new MandateDecision('revoked', $mandate, ['reason' => 'demission']))->lines());

    expect($text)->toContain('mandates.email.revoked');
});

test('l\'email est en français d\'abord, puis en anglais', function () {
    $mandate = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    $lines = (new MandateDecision('approved', $mandate, ['date' => $mandate->valid_until]))->lines();

    expect(array_search(__('mandates.email.greeting', [], 'fr'), $lines))
        ->toBeLessThan(array_search(__('mandates.email.greeting', [], 'en'), $lines));
});

test('les titres anglais sont ceux relevés en jeu, pas des traductions', function () {
    expect(MandateLabels::office('connetable', 'en'))->toBe('Sergeant')
        ->and(MandateLabels::office('prevot_des_marechaux', 'en'))->toBe('Constable')
        ->and(MandateLabels::office('bailli', 'en'))->toBe('Sheriff')
        ->and(MandateLabels::office(null, 'fr'))->toBe('sans poste');
});

test('un motif dont le libellé a disparu s\'affiche par son code, jamais en vide ni en clé brute', function () {
    expect(MandateLabels::reason('motif_retire_depuis', 'fr'))->toBe('motif_retire_depuis')
        ->and(MandateLabels::reason('motif_retire_depuis', 'en'))->toBe('motif_retire_depuis');
});

test('le commentaire libre est étiqueté par la langue dans laquelle il est écrit', function () {
    $mandate = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    $lines = (new MandateDecision('rejected', $mandate, ['reason' => 'autre', 'message' => 'Hello', 'locale' => 'en']))->lines();

    expect($lines)->toContain(__('mandates.email.comment_en', [], 'fr'))
        ->toContain(__('mandates.email.comment_en', [], 'en'))
        ->not->toContain(__('mandates.email.comment_fr', [], 'fr'));
});

// ─── Dates des emails : la chaîne déclare sa convention (fil mandats-historique, 07 et 10) ───
// Date réelle d'abord, date de jeu entre parenthèses — sauf pour un acte de l'Office, qui n'a pas
// de contrepartie dans le jeu. La famille de chaque chaîne est FIGÉE ici : une chaîne nouvelle ou
// réécrite ne peut pas prendre en silence la convention de sa voisine.
dataset('email_date_families', [
    'approved' => ['approved', 'both'],
    'revoked' => ['revoked', 'both'],
    'handover_out' => ['handover_out', 'both'],
    'handover_in' => ['handover_in', 'both'],
    'reminder' => ['reminder', 'both'],
    'reason_corrected' => ['reason_corrected', 'real'],
]);

test('chaque chaîne datée porte la convention décidée, en français et en anglais', function (string $key, string $family) {
    foreach (['fr', 'en'] as $locale) {
        $text = (require lang_path("{$locale}/mandates.php"))['email'][$key];
        if ($family === 'both') {
            expect($text)->toContain(':date (:date_jeu)');
        } else {
            expect($text)->toContain(':date')->not->toContain(':date_jeu');
        }
    }
})->with('email_date_families');

test('toutes les chaînes datées sont classées : aucune n\'échappe au test précédent', function () {
    $dated = collect((require lang_path('fr/mandates.php'))['email'])
        ->filter(fn (string $text) => str_contains($text, ':date'))->keys()->sort()->values()->all();

    expect($dated)->toBe(collect(['approved', 'handover_in', 'handover_out', 'reason_corrected', 'reminder', 'revoked'])->all());
});

test('les emails s\'adressent au joueur et nomment le personnage (10)', function () {
    // Formules qui parlent au PERSONNAGE (« votre mandat », « vous siégez »…). « Vous pouvez refaire
    // une demande » parle au joueur : elle reste permise.
    $frames = [
        'fr' => '/\b[Vv]otre mandat\b|\b[Vv]ous (siégez|entrerez|êtes élu)\b|\b[Vv]otre poste\b/u',
        'en' => '/\b[Yy]our term\b|\b[Yy]ou (sit|will take office|have been sitting)\b|\b[Yy]our office\b/u',
    ];
    foreach ($frames as $locale => $characterFrame) {
        $email = (require lang_path("{$locale}/mandates.php"))['email'];
        foreach ($email as $key => $text) {
            expect(preg_match($characterFrame, $text))->toBe(0, "{$locale}.{$key} s'adresse au personnage : {$text}");
        }
        foreach (['subject', 'approved', 'revoked', 'handover_out', 'handover_in', 'office_removed', 'office_rejected', 'reminder', 'reminder_subject'] as $key) {
            expect($email[$key])->toContain(':character');
        }
    }
});

test('un email rendu montre la date réelle puis la date de jeu, sans marqueur mal remplacé', function () {
    $mandate = approvedMayor(mandatePlayer($this->map['cityA']), $this->map['cityA'], '2026-05-01');
    $notification = new MandateDecision('approved', $mandate, ['date' => $mandate->valid_until]);
    $text = implode("\n", $notification->lines());
    $mail = $notification->toMail($mandate->character->user);

    expect($text)->toContain('31/05/2026 (31/05/1474)')
        ->and($text)->not->toContain('_jeu')
        ->and($text)->toContain($mandate->character->pseudo)
        ->and($mail->subject)->toContain($mandate->character->pseudo);
});
