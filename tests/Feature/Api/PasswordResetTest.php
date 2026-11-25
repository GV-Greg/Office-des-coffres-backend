<?php

use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\PasswordResetLink;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/*
  « Mot de passe oublié » pour les joueurs (fil admin/echanges/mot-de-passe-oublie, décisions de Greg du 24/11/2026). Chaque test garde un engagement du texte
  légal (/legal/privacy §4 et §5) : jeton haché, 60 minutes, à usage unique, effacé à l'usage ou par le nettoyage quotidien, tous les appareils déconnectés,
  email d'avis ; et la règle des emails : un lien vers le SITE DES JOUEURS, jamais le domaine d'administration.
*/

beforeEach(fn () => $this->seed(PassportClientSeeder::class));

const NEW_PASSWORD = 'nouveau_mot_de_passe_123';

// ─── La demande ──────────────────────────────────────────────────────────

test('la demande répond pareil pour un compte qui existe et pour un autre (jamais d\'oracle) ; l\'email ne part que pour le compte', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'joueur@test.com']);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'joueur@test.com']);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@test.com']);

    $known->assertOk();
    $unknown->assertOk();
    expect($unknown->json())->toBe($known->json())->and($known->json('success'))->toBeTrue();
    Notification::assertSentTo($user, PasswordResetLink::class);
    Notification::assertSentTimes(PasswordResetLink::class, 1);
});

test('la demande exige une adresse email valable', function () {
    $this->postJson('/api/v1/auth/forgot-password', [])->assertStatus(422)->assertJsonValidationErrors(['email']);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pas-un-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

test('l\'email est au gabarit de l\'Office, bilingue, et son lien pointe vers le SITE DES JOUEURS — jamais le domaine d\'administration', function () {
    config(['app.frontend_url' => 'https://officedescoffres.creacube.be', 'app.url' => 'https://odc-admin.creacube.be']);
    Notification::fake();
    $user = User::factory()->create(['email' => 'joueur@test.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'joueur@test.com'])->assertOk();

    Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $notification) use ($user) {
        $mail = $notification->toMail($user);
        parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

        expect($mail->actionUrl)->toStartWith('https://officedescoffres.creacube.be/reset-password?')
            ->and($mail->actionUrl)->not->toContain('odc-admin')
            ->and($query['email'])->toBe('joueur@test.com')
            ->and($query['token'])->not->toBe('')
            ->and($mail->subject)->toContain('Réinitialisation')->toContain('Reset your password')
            ->and(implode(' ', $mail->introLines))->toContain('60 minutes')->toContain('expires in 60 minutes');

        return true;
    });
});

test('le jeton est HACHÉ en base : jamais en clair', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    $sentToken = null;

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'joueur@test.com'])->assertOk();
    Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $notification) use ($user, &$sentToken) {
        parse_str((string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);
        $sentToken = $query['token'];

        return true;
    });

    $stored = DB::table('password_reset_tokens')->where('email', 'joueur@test.com')->value('token');
    expect($stored)->not->toBeNull()->and($stored)->not->toBe($sentToken)->and(Hash::check($sentToken, $stored))->toBeTrue();
});

test('la demande est limitée : 3 par minute et par adresse', function () {
    Notification::fake();
    User::factory()->create(['email' => 'joueur@test.com']);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'joueur@test.com'])->assertOk();
    }
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'joueur@test.com'])->assertStatus(429);
    // une autre adresse n'est pas touchée par ce compteur
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'autre@test.com'])->assertOk();
});

// ─── La réinitialisation ─────────────────────────────────────────────────────

test('réinitialiser : le mot de passe change, le jeton ne sert qu\'une fois, l\'avis part', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    $token = Password::broker()->createToken($user);
    $payload = ['token' => $token, 'email' => 'joueur@test.com', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD];

    $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk()->assertJson(['success' => true]);

    expect(Hash::check(NEW_PASSWORD, $user->refresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', 'joueur@test.com')->exists())->toBeFalse(); // effacé à l'usage
    Notification::assertSentTo($user, PasswordChanged::class);
    $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422)->assertJson(['code' => 'invalid_token']); // à usage unique
});

test('réinitialiser déconnecte TOUS les appareils : jetons d\'accès et de rafraîchissement révoqués', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    $first = $user->createToken('appareil-1');
    $second = $user->createToken('appareil-2');
    RefreshToken::create(['id' => 'refresh-1', 'access_token_id' => $first->token->id, 'revoked' => false, 'expires_at' => now()->addDay()]);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'joueur@test.com', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])->assertOk();

    expect(Token::where('user_id', $user->id)->where('revoked', false)->count())->toBe(0)
        ->and(RefreshToken::find('refresh-1')->revoked)->toBeTrue()
        ->and($second->token->refresh()->revoked)->toBeTrue();
});

test('un compte dont l\'email n\'était pas confirmé le devient : lire le lien le prouve (Q4)', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'joueur@test.com']);
    expect($user->hasVerifiedEmail())->toBeFalse();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'joueur@test.com', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])->assertOk();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('jeton faux, adresse inconnue, jeton expiré : UNE SEULE réponse, sans oracle', function () {
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    $good = Password::broker()->createToken($user);
    $body = fn (string $token, string $email) => ['token' => $token, 'email' => $email, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD];

    $wrongToken = $this->postJson('/api/v1/auth/reset-password', $body('mauvais', 'joueur@test.com'));
    $unknownEmail = $this->postJson('/api/v1/auth/reset-password', $body($good, 'inconnu@test.com'));
    Carbon::setTestNow(now()->addMinutes(61));
    $expired = $this->postJson('/api/v1/auth/reset-password', $body($good, 'joueur@test.com'));
    Carbon::setTestNow();

    foreach ([$wrongToken, $unknownEmail, $expired] as $response) {
        $response->assertStatus(422)->assertJson(['success' => false, 'code' => 'invalid_token']);
    }
    expect($unknownEmail->json())->toBe($wrongToken->json())->and($expired->json())->toBe($wrongToken->json())
        ->and(Hash::check('password', $user->refresh()->password))->toBeTrue(); // rien n'a changé
});

test('le nouveau mot de passe est contrôlé : 8 caractères, confirmation identique', function () {
    $user = User::factory()->create(['email' => 'joueur@test.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'joueur@test.com', 'password' => 'court', 'password_confirmation' => 'court'])
        ->assertStatus(422)->assertJsonValidationErrors(['password']);
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'joueur@test.com', 'password' => NEW_PASSWORD, 'password_confirmation' => 'different'])
        ->assertStatus(422)->assertJsonValidationErrors(['password_confirmation']);
    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('la confirmation est limitée (on ne devine pas un jeton)', function () {
    $body = ['token' => 'x', 'email' => 'joueur@test.com', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD];

    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/reset-password', $body)->assertStatus(429);
});

// ─── L'avis, le nettoyage, le formulaire Blade ─────────────────────────────────────────────

test('changer son mot de passe depuis le profil envoie aussi l\'avis (Q5)', function () {
    Notification::fake();
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/v1/auth/account/password', ['current_password' => 'password', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])->assertOk();

    Notification::assertSentTo($user, PasswordChanged::class);
});

test('l\'avis est bilingue et ne contient ni mot de passe ni lien', function () {
    $user = User::factory()->create();
    $mail = (new PasswordChanged)->toMail($user);
    $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

    expect($text)->toContain('Votre mot de passe a été modifié')->toContain('Your password has been changed')
        ->and($mail->actionUrl ?? null)->toBeNull()->and($text)->not->toContain('http');
});

test('le nettoyage quotidien efface les jetons expirés (promesse de la politique §5) et il est planifié', function () {
    DB::table('password_reset_tokens')->insert([
        ['email' => 'vieux@test.com', 'token' => 'x', 'created_at' => now()->subHours(3)],
        ['email' => 'frais@test.com', 'token' => 'y', 'created_at' => now()->subMinutes(5)],
    ]);

    Artisan::call('auth:clear-resets');

    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['frais@test.com']);
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('auth:clear-resets');
});

test('le formulaire Blade de l\'administration envoie lui aussi le lien du SITE DES JOUEURS', function () {
    config(['app.frontend_url' => 'https://officedescoffres.creacube.be']);
    Notification::fake();
    $admin = User::factory()->create(['email' => 'admin@test.com']);

    $this->post('/forgot-password', ['email' => 'admin@test.com']);

    Notification::assertSentTo($admin, PasswordResetLink::class);
});
