<?php

use App\Models\CustomsOfficer;
use App\Models\CustomsReport;
use App\Models\CustomsWatchlistEntry;
use App\Services\CustomsOfficers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
| Douaniers — niveau 3 : l'administrateur, « sur demande » (brief admin/content/brief-module-securite.md §4.1). Page Blade /customs,
| réservée au rôle admin ; même service que l'API.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    $this->applicant = mandatePlayer($this->map['cityA'], ['pseudo' => 'Candidat']);
    $this->officers = app(CustomsOfficers::class);
});

afterEach(fn () => Carbon::setTestNow());

test('la page est réservée à l\'administrateur', function () {
    $this->get('/customs')->assertRedirect();

    $this->actingAs(mandatePlayer($this->map['cityA'])->user)->get('/customs')->assertForbidden();
});

test('l\'administrateur voit les demandes et les douaniers en vigueur', function () {
    $this->officers->request($this->applicant, $this->map['cityA']);

    $this->actingAs(mandateAdmin())->get('/customs')->assertOk()
        ->assertSee('Candidat')->assertSee('Demandes en attente (1)');
});

test('l\'administrateur valide, refuse et révoque (niveau 3, sans personnage décideur)', function () {
    $admin = mandateAdmin();
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    $this->actingAs($admin)->post("/customs/{$officer->id}/approve")->assertRedirect();
    $officer->refresh();
    expect($officer->status)->toBe(CustomsOfficer::APPROVED)->and($officer->decision_level)->toBe(3);

    $this->actingAs($admin)->post("/customs/{$officer->id}/revoke")->assertRedirect();
    expect($officer->refresh()->revocation_reason)->toBe(CustomsOfficer::REASON_ADMIN);

    $second = $this->officers->request($this->applicant, $this->map['cityA']);
    $this->actingAs($admin)->post("/customs/{$second->id}/reject")->assertRedirect();
    expect($second->refresh()->status)->toBe(CustomsOfficer::REJECTED);
});

test('un joueur ne peut pas valider par la page d\'administration', function () {
    $officer = $this->officers->request($this->applicant, $this->map['cityA']);

    $this->actingAs(mandatePlayer($this->map['cityA'])->user)->post("/customs/{$officer->id}/approve")->assertForbidden();
    expect($officer->refresh()->status)->toBe(CustomsOfficer::PENDING);
});

test('le lien Douaniers vit dans le menu de gauche, plus dans celui du dessus', function () {
    $html = $this->actingAs(mandateAdmin())->get('/customs')->assertOk()->getContent();

    // Un seul LIEN vers la page (href exact, les formulaires de la page portent d'autres URL en /customs/…) : la barre latérale. Le menu du haut (bureau et mobile) n'en porte plus.
    expect(substr_count($html, 'href="'.route('customs.index').'"'))->toBe(1)
        ->and($html)->toContain('fixed left-0 top-16');
    preg_match('~<nav class="fixed left-0 top-16.*?</nav>~s', $html, $sidebar);
    expect($sidebar[0] ?? '')->toContain(route('customs.index'));
});

// ─── Retrait d'un pseudo (outil interne hors politique) ────────────────────────────────────

function seedRemovalData($test): void
{
    CustomsWatchlistEntry::create([
        'province_id' => $test->map['provinceA']->id, 'colour' => 'red', 'added_on' => '2026-05-10',
        'payload' => ['pseudo' => 'Pseudo_cible', 'reason' => 'Raison_secrete_unique'],
    ]);
    CustomsWatchlistEntry::create([
        'province_id' => $test->map['provinceA']->id, 'colour' => 'green', 'added_on' => '2026-05-10',
        'payload' => ['pseudo' => 'Autre_pseudo_unique', 'reason' => null],
    ]);
    CustomsReport::create([
        'city_id' => $test->map['cityA']->id, 'reported_on' => '2026-05-10', 'author_role' => 'customs',
        'payload' => ['sections' => ['guet' => [
            'raw' => "Pseudo_cible\t255PR\nVillageois_unique\t12PR", 'parser_version' => 1,
            'report' => ['villagers' => [['pseudo' => 'Pseudo_cible', 'noblesse' => '', 'reputation' => 255], ['pseudo' => 'Villageois_unique', 'noblesse' => '', 'reputation' => 12]], 'declared_total' => 2],
        ]]],
    ]);
}

test('la page propose le formulaire de retrait, réservé à l\'administrateur', function () {
    $this->actingAs(mandateAdmin())->get('/customs')->assertOk()->assertSee('Retirer un pseudo du Registre de douane')->assertSee('remove-pseudo-input', false);

    $player = mandatePlayer($this->map['cityA'])->user;
    $this->actingAs($player)->post('/customs/remove-pseudo', ['pseudo' => 'Pseudo_cible'])->assertForbidden();
    $this->actingAs($player)->post('/customs/remove-pseudo/apply', ['pseudo' => 'Pseudo_cible'])->assertForbidden();
});

test('la simulation ne montre que des NOMBRES, jamais un contenu, et ne modifie rien', function () {
    seedRemovalData($this);

    $html = $this->actingAs(mandateAdmin())->post('/customs/remove-pseudo', ['pseudo' => 'Pseudo_cible'])->assertOk()->getContent();

    expect($html)->toContain('1 entrée(s) de liste')->toContain('1 bilan(s)')->toContain('2 occurrence(s)')->toContain('Retirer définitivement')
        // la page n'est pas une consultation : ni raison, ni autre pseudo, ni texte de bilan, ni couleur, ni province
        ->and($html)->not->toContain('Raison_secrete_unique')->and($html)->not->toContain('Autre_pseudo_unique')->and($html)->not->toContain('Villageois_unique')
        ->and(CustomsWatchlistEntry::count())->toBe(2)
        ->and(json_encode(CustomsReport::first()->payload))->toContain('Pseudo_cible');
});

test('un pseudo absent : la simulation le dit et ne propose aucun retrait', function () {
    seedRemovalData($this);

    $this->actingAs(mandateAdmin())->post('/customs/remove-pseudo', ['pseudo' => 'Inconnu'])->assertOk()
        ->assertSee('0 entrée(s) de liste', false)->assertDontSee('Retirer définitivement');
});

test('le retrait applique la commande : entrée supprimée, bilan nettoyé, rien d\'autre touché ; le pseudo n\'est JAMAIS journalisé', function () {
    seedRemovalData($this);
    Log::spy();

    $this->actingAs(mandateAdmin())->post('/customs/remove-pseudo/apply', ['pseudo' => 'Pseudo_cible'])->assertRedirect(route('customs.index'))
        ->assertSessionHas('status', fn ($status) => str_contains($status, '1 entrée(s) de liste supprimée(s)') && ! str_contains($status, 'Pseudo_cible'));

    expect(CustomsWatchlistEntry::all()->map->pseudo()->all())->toBe(['Autre_pseudo_unique'])
        ->and(json_encode(CustomsReport::first()->payload))->not->toContain('Pseudo_cible')->toContain('Villageois_unique');

    Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => ! str_contains($message.json_encode($context), 'Pseudo_cible') && $context['entries'] === 1);
});

test('un pseudo avec une espace est refusé par le formulaire', function () {
    $this->actingAs(mandateAdmin())->post('/customs/remove-pseudo', ['pseudo' => 'Deux mots'])->assertSessionHasErrors('pseudo');
});
