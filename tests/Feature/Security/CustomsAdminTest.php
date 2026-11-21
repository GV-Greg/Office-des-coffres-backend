<?php

use App\Models\CustomsOfficer;
use App\Services\CustomsOfficers;
use Illuminate\Support\Carbon;
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

    // Une seule occurrence du lien : la barre latérale. Le menu du haut (bureau et mobile) n'en porte plus.
    expect(substr_count($html, route('customs.index')))->toBe(1)
        ->and($html)->toContain('fixed left-0 top-16');
    preg_match('~<nav class="fixed left-0 top-16.*?</nav>~s', $html, $sidebar);
    expect($sidebar[0] ?? '')->toContain(route('customs.index'));
});
