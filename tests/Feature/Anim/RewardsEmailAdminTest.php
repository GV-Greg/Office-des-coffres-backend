<?php

use App\Models\AnimActivityType;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Models\User;
use App\Notifications\AnimEmailConfirmation;
use App\Notifications\AnimPasswordChanged;
use App\Notifications\AnimPasswordResetLink;
use App\Notifications\DecodeFinalReport;
use App\Services\AnimActor;
use App\Services\DecodeWorkflow;
use App\Services\RewardsGridPasswordReset;
use App\Services\RewardsGridWorkflow;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

/*
| Grille des lots — emails (confirmation d'adresse, lien de réinitialisation, avis de changement de mot de passe) et
| administration `/anim` commune avec Décode le code. Option A de Greg, 11/11/2026 : « faire comme Décode ».
| Ce fichier ne dépend d'aucun autre fichier de test (noms d'aides propres).
*/

const GRID_MAIL_PASSWORD = 'un-bon-mot-de-passe';
const GRID_MAIL_IP = '93.184.216.34';

beforeEach(function () {
    Notification::fake();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->map = mandateMap();
    activateRewardsType();
});

function gridMailCreate($test, array $over = [], string $slug = 'grille-des-fees'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => GRID_MAIL_IP])->postJson('/api/v1/anim/rewards-manage', $over + [
        'slug' => $slug,
        'name' => 'Grille des Fées',
        'width' => 3,
        'height' => 3,
        'province_id' => $test->map['provinceA']->id,
        'organizer_name' => 'Elfie',
        'password' => GRID_MAIL_PASSWORD,
        'password_confirmation' => GRID_MAIL_PASSWORD,
    ]);
}

/** Crée la grille avec un email, le confirme par le lien signé, et rend la grille. */
function gridWithVerifiedEmail($test, string $email = 'elfie@example.test'): AnimRewardsGrid
{
    gridMailCreate($test, ['email' => $email])->assertCreated();
    $grid = AnimRewardsGrid::firstOrFail();
    $test->getJson(URL::temporarySignedRoute('anim.rewards.email-confirm', now()->addDay(), ['slug' => $grid->slug, 'hash' => sha1($email)]))->assertOk();

    return $grid->refresh();
}

/** La notification de classe donnée envoyée à l'adresse donnée (à la demande), ou null. */
function gridSentMail(string $class, string $email): ?object
{
    $found = null;
    try {
        Notification::assertSentOnDemand($class, function ($notification, $channels, $notifiable) use (&$found, $email) {
            if (($notifiable->routes['mail'] ?? null) === $email) {
                $found = $notification;

                return true;
            }

            return false;
        });
    } catch (AssertionFailedError) {
        return null;
    }

    return $found;
}

function gridMailText(object $notification): string
{
    $mail = $notification->toMail(new AnonymousNotifiable);

    return $mail->subject."\n".implode("\n", $mail->introLines)."\n".$mail->actionText."\n".$mail->actionUrl;
}

// ─── Confirmation d'adresse ──────────────────────────────────────────────────

test('sans email : aucun message envoyé', function () {
    gridMailCreate($this)->assertCreated();

    Notification::assertNothingSent();
});

test('avec un email : un lien de confirmation part à l\'adresse, bilingue, jamais un mot de passe', function () {
    gridMailCreate($this, ['email' => 'Elfie@Example.test'])->assertCreated()->assertJsonPath('email_pending', true);

    $mail = gridSentMail(AnimEmailConfirmation::class, 'elfie@example.test');
    expect($mail)->not->toBeNull();

    $text = gridMailText($mail);
    expect($text)->toContain('Confirmer mon adresse / Confirm my address')
        ->toContain('Bonjour')   // français d'abord
        ->toContain('Hello')     // puis anglais
        ->toContain('Grille des lots')
        ->toContain('Prize Grid')
        ->not->toContain(GRID_MAIL_PASSWORD)
        // La grille n'envoie aucun compte rendu de fin : le message ne le promet pas.
        ->toContain(__('anim.email.confirm_use_no_report', [], 'fr'))
        ->toContain(__('anim.email.confirm_use_no_report', [], 'en'))
        ->not->toContain(__('anim.email.confirm_use', [], 'fr'))
        ->and($mail->toMail(new AnonymousNotifiable)->actionUrl)->toContain('/app/anim/grille/confirmer-email?')->toContain('signature=');
});

test('le lien signé confirme l\'adresse ; une empreinte fausse est refusée', function () {
    gridMailCreate($this, ['email' => 'elfie@example.test'])->assertCreated();
    $grid = AnimRewardsGrid::firstOrFail();
    expect(DB::table('anim_rewards_grids')->value('organizer_email'))->not->toContain('elfie')
        ->and($grid->organizer_email)->toBe('elfie@example.test');

    $bad = URL::temporarySignedRoute('anim.rewards.email-confirm', now()->addDay(), ['slug' => $grid->slug, 'hash' => sha1('autre@example.test')]);
    $this->getJson($bad)->assertStatus(422);
    expect($grid->refresh()->organizer_email_verified_at)->toBeNull();

    $this->getJson(URL::temporarySignedRoute('anim.rewards.email-confirm', now()->addDay(), ['slug' => $grid->slug, 'hash' => sha1('elfie@example.test')]))->assertOk();
    expect($grid->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('un échec d\'envoi ne bloque pas la création et ne journalise ni l\'adresse ni le message', function () {
    $this->app->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('smtp refusé pour elfie@example.test');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('smtp refusé pour elfie@example.test');
        }
    });
    Log::spy();

    gridMailCreate($this, ['email' => 'elfie@example.test'])->assertCreated();

    $id = AnimRewardsGrid::firstOrFail()->id;
    Log::shouldHaveReceived('warning')->once()->with('anim.mail_failed', ['type' => 'rewards', 'activity' => $id, 'kind' => 'confirmation']);
});

// ─── Réinitialisation du mot de passe ────────────────────────────────────────

test('sans adresse confirmée : la demande est notée pour l\'administrateur, aucun email', function () {
    gridMailCreate($this, ['email' => 'elfie@example.test'])->assertCreated(); // adresse non confirmée
    Notification::fake(); // la confirmation partie à la création n'est pas l'objet du test

    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-request')->assertStatus(202);

    Notification::assertNothingSent();
    $grid = AnimRewardsGrid::firstOrFail();
    expect($grid->reset_requested_at)->not->toBeNull()->and($grid->reset_token_hash)->toBeNull();
});

test('avec une adresse confirmée : le lien part à l\'adresse, l\'auteur choisit son mot de passe, un avis suit', function () {
    gridWithVerifiedEmail($this);
    Notification::fake();

    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-request')->assertStatus(202);

    $mail = gridSentMail(AnimPasswordResetLink::class, 'elfie@example.test');
    expect($mail)->not->toBeNull();
    $url = $mail->toMail(new AnonymousNotifiable)->actionUrl;
    expect($url)->toContain('/app/anim/grille/reinitialiser?slug=grille-des-fees&token=')
        ->and(gridMailText($mail))->toContain('Choisir un nouveau mot de passe / Choose a new password')->not->toContain(GRID_MAIL_PASSWORD);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $new = 'un-tout-nouveau-secret';
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-complete', ['token' => $query['token'], 'password' => $new, 'password_confirmation' => $new])->assertOk();

    $notice = gridSentMail(AnimPasswordChanged::class, 'elfie@example.test');
    expect($notice)->not->toBeNull()->and(gridMailText($notice))->not->toContain($new);

    $grid = AnimRewardsGrid::firstOrFail();
    expect($grid->reset_mode)->toBe('email')->and($grid->reset_token_hash)->toBeNull();

    // Le lien est à usage unique.
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-complete', ['token' => $query['token'], 'password' => $new.'x', 'password_confirmation' => $new.'x'])
        ->assertStatus(422)->assertJsonPath('codes.token', 'reset_invalid');
});

test('slug inconnu ou grille close : même réponse 202, aucun email', function () {
    gridWithVerifiedEmail($this);
    Notification::fake();

    $this->postJson('/api/v1/anim/rewards-manage/introuvable/reset-request')->assertStatus(202);

    $grid = AnimRewardsGrid::firstOrFail();
    app(RewardsGridWorkflow::class)->closeNow($grid);
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-request')->assertStatus(202);

    Notification::assertNothingSent();
});

test('le lien émis par l\'administrateur sans adresse confirmée n\'envoie rien, et le changement n\'est signalé par aucun email', function () {
    gridMailCreate($this)->assertCreated();
    $grid = AnimRewardsGrid::firstOrFail();

    $issued = app(RewardsGridPasswordReset::class)->issueForAdmin($grid);
    parse_str((string) parse_url($issued['url'], PHP_URL_QUERY), $query);
    $new = 'un-tout-nouveau-secret';
    $this->postJson('/api/v1/anim/rewards-manage/grille-des-fees/reset-complete', ['token' => $query['token'], 'password' => $new, 'password_confirmation' => $new])->assertOk();

    Notification::assertNothingSent();
    expect($grid->refresh()->reset_mode)->toBe('admin');
});

test('la clôture d\'une grille ne rend aucun compte par email (contrairement à Décode) et efface l\'adresse', function () {
    $grid = gridWithVerifiedEmail($this);
    Notification::fake();

    app(RewardsGridWorkflow::class)->closeNow($grid);

    Notification::assertNothingSent();
    Notification::assertNotSentTo(new AnonymousNotifiable, DecodeFinalReport::class);
    $raw = DB::table('anim_rewards_grids')->where('id', $grid->id)->first();
    expect($raw->organizer_email)->toBeNull()->and($raw->organizer_email_verified_at)->toBeNull();
});

// ─── Administration /anim, commune avec Décode ───────────────────────────────

function gridAdminAnonymous($test, string $slug = 'grille-sans-compte', ?string $email = null): AnimRewardsGrid
{
    return app(RewardsGridWorkflow::class)->createAnonymous($test->type ?? AnimActivityType::where('key', 'rewards')->firstOrFail(), [
        'slug' => $slug, 'name' => 'Grille sans compte', 'width' => 3, 'height' => 3, 'province_id' => $test->map['provinceA']->id,
        'organizer_name' => 'Elfie', 'password' => GRID_MAIL_PASSWORD, 'email' => $email,
    ], null);
}

test('/anim liste les activités de TOUS les types, avec leur type', function () {
    $grid = gridAdminAnonymous($this);
    $decode = app(DecodeWorkflow::class)->createAnonymous(AnimActivityType::where('key', 'decode')->firstOrFail(), [
        'slug' => 'coffres-sans-compte', 'name' => 'Coffres sans compte', 'organizer_name' => 'Artifice', 'password' => GRID_MAIL_PASSWORD, 'province_id' => $this->map['provinceA']->id,
    ], null);

    $page = $this->actingAs(mandateAdmin())->get('/anim')->assertOk();

    $page->assertSee('Grille sans compte')->assertSee('Coffres sans compte')
        ->assertSee("/anim/activities/rewards/{$grid->id}/close")
        ->assertSee("/anim/activities/decode/{$decode->id}/close");
    expect(substr_count($page->getContent(), 'data-testid="activity-type"'))->toBe(2);
});

test('l\'administrateur émet un lien pour une grille : message prêt à envoyer, nommant la Grille des lots, montré une seule fois', function () {
    config(['app.frontend_url' => 'https://site.example']);
    $grid = gridAdminAnonymous($this);
    $this->actingAs(mandateAdmin());

    $this->post("/anim/activities/rewards/{$grid->id}/reset-link")->assertRedirect(route('anim.index'))->assertSessionHas('reset_link');
    $flash = session('reset_link');

    expect($flash['message'])->toContain('Bonjour Elfie')->toContain('Grille des lots')->toContain('https://site.example/app/anim/grille/reinitialiser?slug=grille-sans-compte&token=')
        ->not->toContain('Décode le code');
    expect($grid->refresh()->reset_token_hash)->toMatch('/^[a-f0-9]{64}$/')->and($grid->reset_mode)->toBe('admin');
    $this->get('/anim')->assertOk()->assertSee('Bonjour Elfie');
    $this->get('/anim')->assertOk()->assertDontSee('Bonjour Elfie');
});

test('l\'administrateur clôt une grille sans compte : définitif, lots conservés, données personnelles effacées', function () {
    $grid = gridAdminAnonymous($this, 'grille-sans-compte', 'elfie@example.test');
    app(RewardsGridWorkflow::class)->addLot(AnimActor::forAnonymous($grid), $grid, 'Épée');
    $this->actingAs(mandateAdmin());

    $this->post("/anim/activities/rewards/{$grid->id}/close")->assertRedirect(route('anim.index'));

    $raw = DB::table('anim_rewards_grids')->where('id', $grid->id)->first();
    expect($raw->status)->toBe('closed')->and($raw->password_hash)->toBeNull()->and($raw->organizer_email)->toBeNull()
        ->and(AnimRewardsList::where('grid_id', $grid->id)->count())->toBe(1);
});

test('supprimer une grille : étape de confirmation, puis suppression avec ses lots', function () {
    $grid = gridAdminAnonymous($this);
    app(RewardsGridWorkflow::class)->addLot(AnimActor::forAnonymous($grid), $grid, 'Épée');
    $this->actingAs(mandateAdmin());

    $this->get("/anim/activities/rewards/{$grid->id}/delete")->assertOk()->assertSee('Grille sans compte')
        ->assertSee("/anim/activities/rewards/{$grid->id}", false);
    expect(AnimRewardsGrid::count())->toBe(1);

    $this->delete("/anim/activities/rewards/{$grid->id}")->assertRedirect(route('anim.index'));

    expect(AnimRewardsGrid::count())->toBe(0)->and(AnimRewardsList::count())->toBe(0);
});

test('une grille PRIVÉE n\'a aucun geste d\'administration ; un type ou un identifiant inconnu est introuvable ; l\'accès reste réservé', function () {
    $orga = mandatePlayer($this->map['cityA']);
    $private = app(RewardsGridWorkflow::class)->create($orga, AnimActivityType::where('key', 'rewards')->firstOrFail(), [
        'slug' => 'grille-privee', 'name' => 'Grille privée', 'width' => 3, 'height' => 3, 'province_id' => $this->map['provinceA']->id,
    ]);

    $this->actingAs(mandateAdmin());
    foreach (['post' => 'close', 'get' => 'delete', 'post ' => 'reset-link'] as $method => $path) {
        $this->{trim($method)}("/anim/activities/rewards/{$private->id}/{$path}")->assertRedirect(route('anim.index'))->assertSessionHasErrors('activity');
    }
    $this->delete("/anim/activities/rewards/{$private->id}")->assertRedirect(route('anim.index'));
    expect(AnimRewardsGrid::count())->toBe(1);
    $this->get('/anim')->assertDontSee("/anim/activities/rewards/{$private->id}/delete");

    $this->post('/anim/activities/inconnu/1/close')->assertNotFound();
    $this->post('/anim/activities/rewards/99999/close')->assertNotFound();

    auth()->logout();
    $this->actingAs(User::factory()->create())->post("/anim/activities/rewards/{$private->id}/close")->assertForbidden();
});
