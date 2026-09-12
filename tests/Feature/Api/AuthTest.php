<?php

use App\Models\City;
use App\Models\User;
use App\Notifications\VerifyApiEmail;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Spatie\Permission\Models\Role;

// Le client OAuth "password grant" (utilisé par login()/refresh() en interne) n'existe qu'en
// base — RefreshDatabase ne seed rien automatiquement (voir Pest.php), donc chaque test qui
// passe par le vrai flux OAuth doit le recréer explicitement.
beforeEach(fn () => $this->seed(PassportClientSeeder::class));

// --- Register ---

test('un utilisateur peut créer un compte', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'email'        => 'artifice@test.com',
        'password'     => 'password123',
        'confirmation' => 'password123',
    ]);

    $response->assertStatus(201)->assertJsonPath('success', true);

    $user = User::where('email', 'artifice@test.com')->first();
    expect($user)->not->toBeNull();
    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyApiEmail::class);
});

test('l\'inscription échoue si l\'email est déjà utilisé', function () {
    User::factory()->create(['email' => 'pris@test.com']);

    $this->postJson('/api/v1/auth/register', [
        'email'        => 'pris@test.com',
        'password'     => 'password123',
        'confirmation' => 'password123',
    ])->assertStatus(422)->assertJsonPath('errors.email.0', fn ($msg) => str_contains($msg, 'déjà'));
});

test('l\'inscription échoue si la confirmation ne correspond pas', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'        => 'test@test.com',
        'password'     => 'password123',
        'confirmation' => 'different',
    ])->assertStatus(422)->assertJsonValidationErrors(['confirmation']);
});

test('l\'inscription échoue si le mot de passe est trop court', function () {
    $this->postJson('/api/v1/auth/register', [
        'email'        => 'test@test.com',
        'password'     => 'court',
        'confirmation' => 'court',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

// --- Verify email ---

function signedVerifyUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify.api', now()->addMinutes(60), [
        'id'   => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);
}

test('le lien de vérification confirme l\'email et connecte automatiquement', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->get(signedVerifyUrl($user));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(config('app.frontend_url') . '/verify-email?token=');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('un lien de vérification avec un hash incorrect redirige avec une erreur', function () {
    $user = User::factory()->unverified()->create();

    // Signature Laravel valide, mais hash ne correspondant pas à l'email du user
    // (simule un lien altéré/pour un autre compte) : doit être rejeté par le contrôleur,
    // pas seulement par le middleware `signed`.
    $url = URL::temporarySignedRoute('verification.verify.api', now()->addMinutes(60), [
        'id'   => $user->id,
        'hash' => sha1('autre-email@test.com'),
    ]);

    $response = $this->get($url);

    $response->assertRedirect(config('app.frontend_url') . '/verify-email?error=invalid');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('un lien de vérification altéré (signature invalide) est rejeté', function () {
    $user = User::factory()->unverified()->create();

    $this->get('/api/v1/auth/verify-email/' . $user->id . '/wronghash?expires=9999999999&signature=invalid')
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('on peut redemander un email de vérification', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->postJson('/api/v1/auth/resend-verification', ['email' => $user->email])
        ->assertOk()->assertJsonPath('success', true);

    Notification::assertSentTo($user, VerifyApiEmail::class);
});

test('redemander un email pour un compte déjà vérifié ne renvoie rien', function () {
    Notification::fake();
    $user = User::factory()->create(); // vérifié par défaut

    $this->postJson('/api/v1/auth/resend-verification', ['email' => $user->email])
        ->assertOk()->assertJsonPath('success', true);

    Notification::assertNothingSent();
});

// --- Login ---

test('un utilisateur peut se connecter avec son email', function () {
    $user = User::factory()->create(['email' => 'artifice@test.com', 'password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'artifice@test.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
             ->assertJsonStructure(['success', 'access_token', 'refresh_token', 'expires_in', 'user' => ['id', 'email', 'is_admin', 'characters']])
             ->assertJsonPath('success', true)
             ->assertJsonPath('user.email', 'artifice@test.com')
             ->assertJsonPath('user.is_admin', false);
});

test('la connexion indique is_admin=true pour un compte avec le rôle admin', function () {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $user->assignRole('admin');

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ])->assertOk()->assertJsonPath('user.is_admin', true);
});

test('le login échoue si l\'email n\'est pas vérifié', function () {
    $user = User::factory()->unverified()->create(['password' => bcrypt('password123')]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ])->assertStatus(403)
      ->assertJsonPath('success', false)
      ->assertJsonPath('message', 'Email non vérifié.');
});

test('le login échoue avec un mauvais mot de passe', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'mauvais',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

test('le login échoue avec un email inconnu', function () {
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'inconnu@test.com',
        'password' => 'password123',
    ])->assertStatus(401)->assertJsonPath('success', false);
});

test('la connexion retourne la liste des personnages du compte', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $city = City::factory()->create();
    $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => true]);
    $user->characters()->create(['pseudo' => 'Buldo', 'city_id' => $city->id, 'is_validated' => false]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ]);

    $response->assertOk();
    expect($response->json('user.characters'))->toHaveCount(2);
});

// --- Me ---

test('un utilisateur authentifié peut récupérer son profil avec ses personnages', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => false]);

    Passport::actingAs($user);
    $this->getJson('/api/v1/auth/me')
         ->assertOk()
         ->assertJsonPath('success', true)
         ->assertJsonPath('user.email', $user->email)
         ->assertJsonPath('user.is_admin', false)
         ->assertJsonPath('user.characters.0.pseudo', 'Artifice');
});

test('/me retourne une liste vide si le compte n\'a pas encore de personnage', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);
    $this->getJson('/api/v1/auth/me')
         ->assertOk()
         ->assertJsonPath('user.characters', []);
});

test('/me retourne 401 sans token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

// --- Logout ---

test('un utilisateur peut se déconnecter', function () {
    $user  = User::factory()->create();
    // Token personnel réellement persisté (contrairement à Passport::actingAs, en mémoire
    // seulement) : logout() appelle ->token()->revoke(), qui a besoin d'une ligne DB réelle.
    $token = $user->createToken('api-token')->accessToken;

    $this->withToken($token)
         ->postJson('/api/v1/auth/logout')
         ->assertOk()
         ->assertJsonPath('success', true);
});

// --- Refresh ---

test('un utilisateur peut rafraîchir son token via le refresh_token', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $login->json('refresh_token'),
    ]);

    $response->assertOk()
             ->assertJsonStructure(['success', 'access_token', 'refresh_token', 'expires_in'])
             ->assertJsonPath('success', true);

    expect($response->json('access_token'))->not->toBe($login->json('access_token'));
});

test('le refresh échoue avec un refresh_token invalide', function () {
    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => 'invalide',
    ])->assertStatus(401)
      ->assertJsonPath('success', false)
      ->assertJsonPath('message', 'Session expirée, reconnecte-toi.');
});

// oauth_refresh_tokens n'a pas de colonne created_at (stub Passport standard) : on retrouve
// la ligne exacte par access_token_id (même technique que
// AuthController::shortenRefreshTokenExpiration) plutôt que par un tri approximatif.
function refreshTokenFor(string $accessToken): RefreshToken
{
    $payload = json_decode(base64_decode(strtr(explode('.', $accessToken)[1] ?? '', '-_', '+/')), true);

    return RefreshToken::where('access_token_id', $payload['jti'])->firstOrFail();
}

test('remember_me=false raccourcit l\'expiration du refresh token à 12h, contre 30 jours si coché', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    $withoutRememberMe = $this->postJson('/api/v1/auth/login', [
        'email'       => $user->email,
        'password'    => 'password123',
        'remember_me' => false,
    ]);
    $shortLived = refreshTokenFor($withoutRememberMe->json('access_token'));

    $withRememberMe = $this->postJson('/api/v1/auth/login', [
        'email'       => $user->email,
        'password'    => 'password123',
        'remember_me' => true,
    ]);
    $longLived = refreshTokenFor($withRememberMe->json('access_token'));

    expect($shortLived->expires_at)->toBeLessThan(now()->addDay());
    expect($longLived->expires_at)->toBeGreaterThan(now()->addDays(29));
});

// --- Suppression de compte (art. 17 RGPD) ---

test('un utilisateur peut supprimer son compte avec son mot de passe', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $city = City::factory()->create();
    $user->characters()->create(['pseudo' => 'Artifice', 'city_id' => $city->id, 'is_validated' => true]);

    // Vrai couple access+refresh token : on veut vérifier que les deux disparaissent, ce que
    // Passport::actingAs (en mémoire) ne permettrait pas de contrôler.
    $login = $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password123',
    ]);
    $refreshToken = refreshTokenFor($login->json('access_token'));

    $this->withToken($login->json('access_token'))
         ->deleteJson('/api/v1/auth/account', ['password' => 'password123'])
         ->assertNoContent();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('characters', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('oauth_refresh_tokens', ['id' => $refreshToken->id]);
});

test('la suppression échoue avec un mot de passe incorrect et ne touche à rien', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $city = City::factory()->create();
    $user->characters()->create(['pseudo' => 'Buldo', 'city_id' => $city->id, 'is_validated' => true]);

    Passport::actingAs($user);
    $this->deleteJson('/api/v1/auth/account', ['password' => 'mauvais-mot-de-passe'])
         ->assertStatus(403)
         ->assertJsonPath('success', false)
         ->assertJsonPath('message', 'Mot de passe incorrect.');

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('characters', ['user_id' => $user->id, 'pseudo' => 'Buldo']);
});

test('la suppression exige le mot de passe', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);
    $this->deleteJson('/api/v1/auth/account', [])
         ->assertStatus(422)
         ->assertJsonValidationErrors('password');

    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('la suppression de compte retourne 401 sans token', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    $this->deleteJson('/api/v1/auth/account', ['password' => 'password123'])
         ->assertStatus(401);

    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('la suppression ne porte que sur le compte du porteur du jeton', function () {
    $victime = User::factory()->create(['password' => bcrypt('password123')]);
    $city    = City::factory()->create();
    $victime->characters()->create(['pseudo' => 'Innocent', 'city_id' => $city->id, 'is_validated' => true]);

    $attaquant = User::factory()->create(['password' => bcrypt('password123')]);

    Passport::actingAs($attaquant);
    // Aucun identifiant n'est accepté : ni en body, ni dans l'URL (la route n'existe pas).
    $this->deleteJson('/api/v1/auth/account', [
        'password' => 'password123',
        'user_id'  => $victime->id,
        'id'       => $victime->id,
    ])->assertNoContent();

    $this->deleteJson("/api/v1/auth/account/{$victime->id}", ['password' => 'password123'])
         ->assertStatus(404);

    $this->assertDatabaseMissing('users', ['id' => $attaquant->id]);
    $this->assertDatabaseHas('users', ['id' => $victime->id]);
    $this->assertDatabaseHas('characters', ['user_id' => $victime->id, 'pseudo' => 'Innocent']);
});
