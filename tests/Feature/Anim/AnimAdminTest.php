<?php

use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\User;
use App\Notifications\DecodeFinalReport;
use App\Services\DecodeActor;
use App\Services\DecodeClosing;
use App\Services\DecodeWorkflow;
use App\Support\DecodeSlug;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
| Animation — administration Blade (role:admin) : types d'activité, surveillance et RECOURS pour les activités SANS COMPTE
| uniquement (D13 : clore, retirer avec confirmation ; D32 : adresse confirmée visible ; D36 : lien de réinitialisation remis
| via le jeu ; D37 : trace). Les activités privées appartiennent à leur organisateur : aucun geste d'ici.
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->type = AnimActivityType::where('key', 'decode')->firstOrFail();
    $this->workflow = app(DecodeWorkflow::class);
});

function adminAnonymous($test, string $slug = 'sans-compte', array $extra = []): AnimDecodeActivity
{
    return $test->workflow->createAnonymous($test->type, $extra + [
        'slug' => $slug, 'name' => 'Sans compte', 'organizer_name' => 'Artifice', 'password' => 'un-bon-mot-de-passe',
    ], null);
}

test('la page est réservée à l\'administrateur', function () {
    $this->get('/anim')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/anim')->assertForbidden();
    $this->actingAs(mandateAdmin())->get('/anim')->assertOk()->assertSee('Activités récentes');
});

test('le toggle active et désactive un type, sans le supprimer', function () {
    $this->actingAs(mandateAdmin());

    $this->patch("/anim/types/{$this->type->id}/toggle")->assertRedirect(route('anim.index'));
    expect($this->type->refresh()->is_active)->toBeFalse();

    $this->patch("/anim/types/{$this->type->id}/toggle");
    expect($this->type->refresh()->is_active)->toBeTrue();
});

test('un joueur non administrateur ne peut rien : types, clôture, suppression, lien', function () {
    $anon = adminAnonymous($this);
    $this->actingAs(User::factory()->create());

    $this->patch("/anim/types/{$this->type->id}/toggle")->assertForbidden();
    $this->post("/anim/activities/{$anon->id}/close")->assertForbidden();
    $this->get("/anim/activities/{$anon->id}/delete")->assertForbidden();
    $this->delete("/anim/activities/{$anon->id}")->assertForbidden();
    $this->post("/anim/activities/{$anon->id}/reset-link")->assertForbidden();

    expect($this->type->refresh()->is_active)->toBeTrue()->and($anon->refresh()->isClosed())->toBeFalse()->and(AnimDecodeActivity::count())->toBe(1);
});

test('les activités récentes montrent mode, organisateur, lieu et état — l\'organisateur privé se lit sur son personnage', function () {
    $orga = mandatePlayer($this->map['cityA']);
    $this->workflow->create($orga, $this->type, ['name' => 'Coffre des Marches', 'slug' => 'coffre-des-marches', 'province_id' => $this->map['provinceA']->id]);
    adminAnonymous($this, 'sans-compte', ['kingdom_id' => $this->map['provinceA']->kingdom_id]);

    $this->actingAs(mandateAdmin())->get('/anim')
        ->assertOk()
        ->assertSee('Coffre des Marches')->assertSee($orga->pseudo)->assertSee('Artois')->assertSee('Privée')
        ->assertSee('Sans compte')->assertSee('Artifice')->assertSee('Royaume');
});

test('une activité privée dont le compte est supprimé disparaît de la liste (D21)', function () {
    $orga = mandatePlayer($this->map['cityA']);
    $this->workflow->create($orga, $this->type, ['name' => 'Coffre des Marches', 'slug' => 'coffre-des-marches']);

    $orga->delete();

    $this->actingAs(mandateAdmin())->get('/anim')->assertOk()->assertDontSee('Coffre des Marches');
    expect(AnimDecodeActivity::count())->toBe(0);
});

// ── D32 : l'adresse n'est montrée que si elle est confirmée ─────────────────────────────────────

test('l\'adresse CONFIRMÉE est visible de l\'administrateur ; une adresse non confirmée ne l\'est jamais', function () {
    Notification::fake();
    $confirmed = adminAnonymous($this, 'confirmee', ['email' => 'confirmee@example.test']);
    adminAnonymous($this, 'pasconfirmee', ['email' => 'secrete@example.test']);
    $this->getJson(URL::temporarySignedRoute('anim.decode.email-confirm', now()->addDay(), ['slug' => 'confirmee', 'hash' => sha1('confirmee@example.test')]))->assertOk();

    $page = $this->actingAs(mandateAdmin())->get('/anim')->assertOk();

    $page->assertSee('confirmee@example.test')->assertDontSee('secrete@example.test')->assertSee('non confirmé');
    expect($confirmed->refresh()->hasVerifiedEmail())->toBeTrue();
});

// ── D13 : clore, supprimer avec confirmation ──────────────────────────────────────────────────────────

test('l\'administrateur clôt une activité sans compte : résumé figé, données effacées, compte rendu à l\'adresse confirmée', function () {
    Notification::fake();
    $anon = adminAnonymous($this, 'sans-compte', ['email' => 'a@example.test']);
    $this->getJson(URL::temporarySignedRoute('anim.decode.email-confirm', now()->addDay(), ['slug' => 'sans-compte', 'hash' => sha1('a@example.test')]))->assertOk();
    $this->workflow->generateSecret(DecodeActor::forAnonymous($anon->refresh()), $anon);

    $this->actingAs(mandateAdmin())->post("/anim/activities/{$anon->id}/close")->assertRedirect(route('anim.index'));

    $anon->refresh();
    expect($anon->isClosed())->toBeTrue()->and($anon->closing_snapshot)->not->toBeNull()->and($anon->password_hash)->toBeNull()->and($anon->organizer_email)->toBeNull();
    Notification::assertSentOnDemand(DecodeFinalReport::class);
});

test('la suppression passe par une ÉTAPE DE CONFIRMATION, jamais en un clic', function () {
    $anon = adminAnonymous($this);
    $this->actingAs(mandateAdmin());

    // 1) la page de confirmation nomme l'activité et ce qui disparaît ; rien n'est supprimé.
    $this->get("/anim/activities/{$anon->id}/delete")
        ->assertOk()->assertSee('Supprimer l\'activité « Sans compte »')->assertSee('sans-compte')->assertSee('Oui, supprimer définitivement');
    expect(AnimDecodeActivity::count())->toBe(1);

    // 2) seule la requête DELETE de cette page supprime.
    $this->delete("/anim/activities/{$anon->id}")->assertRedirect(route('anim.index'));
    expect(AnimDecodeActivity::count())->toBe(0);
});

test('le slug redevient libre après la suppression par l\'administration', function () {
    $anon = adminAnonymous($this);
    $this->actingAs(mandateAdmin())->delete("/anim/activities/{$anon->id}");

    expect(DecodeSlug::taken('sans-compte'))->toBeFalse();
});

test('aucun geste sur une activité PRIVÉE : clore, supprimer et lien sont refusés avec un message', function () {
    $orga = mandatePlayer($this->map['cityA']);
    $private = $this->workflow->create($orga, $this->type, ['name' => 'Privée', 'slug' => 'privee']);
    $this->actingAs(mandateAdmin());

    $this->post("/anim/activities/{$private->id}/close")->assertRedirect(route('anim.index'))->assertSessionHasErrors('activity');
    $this->get("/anim/activities/{$private->id}/delete")->assertRedirect(route('anim.index'))->assertSessionHasErrors('activity');
    $this->delete("/anim/activities/{$private->id}")->assertRedirect(route('anim.index'))->assertSessionHasErrors('activity');
    $this->post("/anim/activities/{$private->id}/reset-link")->assertRedirect(route('anim.index'))->assertSessionHasErrors('activity');

    expect($private->refresh()->isClosed())->toBeFalse()->and(AnimDecodeActivity::count())->toBe(1);
    // La page n'offre d'ailleurs aucun bouton pour elle.
    $this->get('/anim')->assertDontSee("/anim/activities/{$private->id}/delete");
});

// ── D36, D37 : lien de réinitialisation pour un auteur sans email ──────────────────────────────────────

test('l\'administrateur émet un lien à usage unique, montré UNE SEULE FOIS, prêt à envoyer au personnage dans le jeu', function () {
    config(['app.frontend_url' => 'https://site.example']);
    $anon = adminAnonymous($this);
    $this->actingAs(mandateAdmin());

    $this->post("/anim/activities/{$anon->id}/reset-link")->assertRedirect(route('anim.index'))->assertSessionHas('reset_link');
    $flash = session('reset_link');

    expect($flash['message'])->toContain('Bonjour Artifice')->toContain('Sans compte')->toContain('https://site.example/app/anim/decode/reinitialiser?slug=sans-compte&token=')
        ->and($flash['heading'])->toContain('Artifice');

    // Montré une fois : à l'affichage suivant, il n'existe plus nulle part (le jeton n'est stocké que haché).
    $page = $this->get('/anim')->assertOk()->assertSee('Bonjour Artifice');
    $this->get('/anim')->assertOk()->assertDontSee('Bonjour Artifice');
    expect($anon->refresh()->reset_token_hash)->toMatch('/^[a-f0-9]{64}$/')->and($anon->reset_mode)->toBe('admin');
});

test('le lien émis par l\'administrateur permet à l\'auteur de choisir son mot de passe — l\'administrateur ne le connaît jamais', function () {
    $anon = adminAnonymous($this);
    $this->actingAs(mandateAdmin())->post("/anim/activities/{$anon->id}/reset-link");
    preg_match('/token=([a-f0-9]{64})/', session('reset_link')['message'], $m);

    $new = 'un-tout-nouveau-secret';
    $this->postJson('/api/v1/anim/decode-manage/sans-compte/reset-complete', ['token' => $m[1], 'password' => $new, 'password_confirmation' => $new])->assertOk();

    $this->postJson('/api/v1/anim/decode-manage/sans-compte/open', ['password' => $new])->assertOk();
    $anon->refresh();
    // D37 : la trace garde la date et le mode, jamais l'identité de l'administrateur du site.
    expect($anon->password_reset_at)->not->toBeNull()->and($anon->reset_mode)->toBe('admin');
    $this->actingAs(mandateAdmin())->get('/anim')->assertSee('par le lien remis via le jeu');
});

test('la demande de lien d\'un auteur SANS email confirmé est notée, visible de l\'administrateur, et effacée dès que le lien est émis', function () {
    $anon = adminAnonymous($this);

    $this->postJson('/api/v1/anim/decode-manage/sans-compte/reset-request')->assertStatus(202);
    expect($anon->refresh()->reset_requested_at)->not->toBeNull();
    $this->actingAs(mandateAdmin())->get('/anim')->assertOk()->assertSee('Lien demandé le');

    $this->post("/anim/activities/{$anon->id}/reset-link");
    expect($anon->refresh()->reset_requested_at)->toBeNull();
});

test('la demande de lien répond pareil pour une activité inconnue, privée ou close — et ne note rien', function () {
    $closed = adminAnonymous($this, 'activite-close');
    app(DecodeClosing::class)->close($closed);

    $unknown = $this->postJson('/api/v1/anim/decode-manage/inconnue/reset-request');
    $known = $this->postJson('/api/v1/anim/decode-manage/activite-close/reset-request');

    expect($unknown->status())->toBe(202)->and($known->status())->toBe(202)->and($known->json())->toBe($unknown->json());
    expect($closed->refresh()->reset_requested_at)->toBeNull();
});

test('aucun lien pour une activité sans compte déjà close', function () {
    $anon = adminAnonymous($this);
    $this->workflow->closeNow($anon);

    $this->actingAs(mandateAdmin())->post("/anim/activities/{$anon->id}/reset-link")->assertRedirect(route('anim.index'))->assertSessionHasErrors('activity');
    expect(session('reset_link'))->toBeNull();
});
