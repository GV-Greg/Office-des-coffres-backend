<?php

use App\Models\City;
use App\Models\User;
use App\Notifications\VerifyApiEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

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
             ->assertJsonStructure(['success', 'token', 'user' => ['id', 'email', 'is_admin', 'characters']])
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

    $this->actingAs($user, 'sanctum')
         ->getJson('/api/v1/auth/me')
         ->assertOk()
         ->assertJsonPath('success', true)
         ->assertJsonPath('user.email', $user->email)
         ->assertJsonPath('user.is_admin', false)
         ->assertJsonPath('user.characters.0.pseudo', 'Artifice');
});

test('/me retourne une liste vide si le compte n\'a pas encore de personnage', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
         ->getJson('/api/v1/auth/me')
         ->assertOk()
         ->assertJsonPath('user.characters', []);
});

test('/me retourne 401 sans token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

// --- Logout ---

test('un utilisateur peut se déconnecter', function () {
    $user  = User::factory()->create();
    $token = $user->createToken('api-token')->plainTextToken;

    $this->withToken($token)
         ->postJson('/api/v1/auth/logout')
         ->assertOk()
         ->assertJsonPath('success', true);
});
