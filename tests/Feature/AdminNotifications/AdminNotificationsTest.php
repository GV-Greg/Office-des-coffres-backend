<?php

use App\Models\City;
use App\Models\CouncilMandate;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminEventNotice;
use App\Services\AdminNotifier;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;

/*
|--------------------------------------------------------------------------
| Notifications d'administration
|--------------------------------------------------------------------------
| Brief : admin/content/brief-notifications-admin.md. Fil : admin/echanges/notifications-admin/.
| Arbitrages de Greg (17/11/2026) : comptes `admin` destinataires, compte signalé à la CONFIRMATION
| de l'adresse (jamais avec l'adresse), renouvellements inclus, français seul.
*/

// La confirmation de l'adresse émet un jeton d'accès personnel : le client Passport doit exister.
beforeEach(fn () => $this->seed(PassportClientSeeder::class));

afterEach(fn () => Carbon::setTestNow());

function noticeAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function noticeText(AdminEventNotice $notice): string
{
    return $notice->subject."\n".implode("\n", $notice->lines);
}

function noticeVerifyUrl(User $user): string
{
    return URL::temporarySignedRoute('verification.verify.api', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);
}

// ─── Compte ──────────────────────────────────────────────────────────────────

test('compte : l\'inscription seule ne prévient personne, la confirmation de l\'adresse prévient les admins', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $player = User::factory()->unverified()->create(['email' => 'joueur.secret@example.test']);

    // L'inscription (API) n'envoie rien à l'administrateur : un faux compte non confirmé ne compte pas.
    $this->postJson('/api/v1/auth/register', [
        'email' => 'nouveau@example.test', 'password' => 'motdepasse1', 'confirmation' => 'motdepasse1',
    ])->assertCreated();
    Notification::assertNotSentTo($admin, AdminEventNotice::class);

    $this->get(noticeVerifyUrl($player))->assertRedirect();

    Notification::assertSentTo($admin, AdminEventNotice::class, function (AdminEventNotice $notice) use ($player) {
        $text = noticeText($notice);

        return $notice->subject === 'Nouveau compte confirmé'
            && str_contains($text, "Compte n° {$player->id}")
            && str_contains($text, 'comptes confirmés au total')
            // 🔴 Jamais l'adresse du joueur dans le corps.
            && ! str_contains($text, 'joueur.secret')
            && ! str_contains($text, '@')
            && str_ends_with($notice->actionUrl, '/users');
    });
});

test('compte : un lien de confirmation rejoué ne prévient pas une seconde fois', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $player = User::factory()->unverified()->create();

    $this->get(noticeVerifyUrl($player));
    $this->get(noticeVerifyUrl($player));

    Notification::assertSentToTimes($admin, AdminEventNotice::class, 1);
});

test('seuls les comptes admin reçoivent la notification', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $other = User::factory()->create();
    $player = User::factory()->unverified()->create();

    $this->get(noticeVerifyUrl($player));

    Notification::assertSentTo($admin, AdminEventNotice::class);
    Notification::assertNotSentTo($other, AdminEventNotice::class);
});

// ─── Personnage ──────────────────────────────────────────────────────────────

test('personnage : la création prévient les admins (pseudo, résidence, compte), sans adresse email', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $user = User::factory()->create(['email' => 'joueur.secret@example.test']);
    $city = City::factory()->create();

    Passport::actingAs($user);
    $this->postJson('/api/v1/characters', ['pseudo' => 'Artifice', 'city_id' => $city->id])->assertCreated();

    Notification::assertSentTo($admin, AdminEventNotice::class, function (AdminEventNotice $notice) use ($user, $city) {
        $text = noticeText($notice);

        return $notice->subject === 'Nouveau personnage créé'
            && str_contains($text, 'Artifice')
            && str_contains($text, $city->city_name)
            && str_contains($text, $city->province->province_name)
            && str_contains($text, "Compte n° {$user->id}")
            && ! str_contains($text, '@')
            && str_ends_with($notice->actionUrl, '/characters');
    });
});

test('personnage : un pseudo refusé (déjà pris) ne prévient personne', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $city = City::factory()->create();
    mandatePlayer($city, ['pseudo' => 'Artifice']);

    Passport::actingAs(User::factory()->create());
    $this->postJson('/api/v1/characters', ['pseudo' => 'Artifice', 'city_id' => $city->id])->assertStatus(422);

    Notification::assertNothingSent();
});

// ─── Demande de poste et renouvellement ──────────────────────────────────────

test('poste : une demande de mairie prévient les admins avec le poste, le lieu et la date de début', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($map['cityA']);
    Passport::actingAs($character->user);

    $this->postJson("/api/v1/characters/{$character->id}/mandates", [
        'level' => 'mayor', 'city_id' => $map['cityA']->id,
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/annonce',
    ])->assertCreated();

    Notification::assertSentTo($admin, AdminEventNotice::class, function (AdminEventNotice $notice) use ($character, $map) {
        $text = noticeText($notice);

        return $notice->subject === 'Nouvelle demande de poste'
            && str_contains($text, $character->pseudo)
            && str_contains($text, $map['cityA']->city_name)
            && str_contains($text, '01/05/2026')
            && ! str_contains($text, 'forum.example')
            && str_ends_with($notice->actionUrl, '/mandates');
    });
});

test('poste : une demande au conseil nomme le titre demandé', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($map['cityA']);
    Passport::actingAs($character->user);

    $this->postJson("/api/v1/characters/{$character->id}/mandates", [
        'level' => 'council', 'province_id' => $map['provinceA']->id, 'council_office_key' => 'bailli',
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/conseil',
    ])->assertCreated();

    Notification::assertSentTo($admin, AdminEventNotice::class, fn (AdminEventNotice $notice) => str_contains(noticeText($notice), 'Titre demandé : Bailli'));
});

test('poste : une demande refusée (personnage non validé) ne prévient personne', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($map['cityA'], ['is_validated' => false]);
    Passport::actingAs($character->user);

    $this->postJson("/api/v1/characters/{$character->id}/mandates", [
        'level' => 'mayor', 'city_id' => $map['cityA']->id,
        'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/annonce',
    ])->assertStatus(422);

    Notification::assertNothingSent();
});

test('poste : un renouvellement prévient aussi, sous un sujet distinct', function () {
    Notification::fake();
    $admin = noticeAdmin();
    $map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $character = mandatePlayer($map['cityA']);
    $mandate = CouncilMandate::create([
        'character_id' => $character->id, 'province_id' => $map['provinceA']->id,
        'status' => 'approved', 'declared_started_at' => '2026-03-01', 'started_at' => '2026-03-01',
        'announcement_url' => 'https://forum.example/a', 'in_office_from' => '2026-03-01 08:00:00',
        'valid_until' => '2026-04-30 08:00:00', 'holds_until' => '2026-05-09 08:00:00',
    ]);
    Passport::actingAs($character->user);

    $this->postJson("/api/v1/mandates/council/{$mandate->id}/renew", [
        'started_at' => '2026-05-09', 'announcement_url' => 'https://forum.example/b',
    ]);

    Notification::assertSentTo($admin, AdminEventNotice::class, fn (AdminEventNotice $notice) => $notice->subject === 'Nouvelle demande de renouvellement'
        && str_contains(noticeText($notice), $character->pseudo));
});

// ─── Robustesse ──────────────────────────────────────────────────────────────

test('le rôle admin est trouvé même sous un jeton API (garde `api` par défaut, rôle créé sous `web`)', function () {
    Notification::fake();
    $admin = noticeAdmin();
    Passport::actingAs(User::factory()->create());
    expect(config('auth.defaults.guard'))->toBe('api');

    app(AdminNotifier::class)->accountConfirmed(User::factory()->create());

    Notification::assertSentTo($admin, AdminEventNotice::class);
});

test('une panne d\'envoi ne bloque jamais le geste du joueur, et se journalise sans l\'adresse', function () {
    noticeAdmin();
    Log::spy();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp down pour joueur.secret@example.test'));
    $city = City::factory()->create();

    Passport::actingAs(User::factory()->create());
    $this->postJson('/api/v1/characters', ['pseudo' => 'Artifice', 'city_id' => $city->id])->assertCreated();

    $this->assertDatabaseHas('characters', ['pseudo' => 'Artifice']);
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        return $message === 'admin.notification_failed'
            && $context['kind'] === 'character'
            && $context['exception'] === RuntimeException::class
            && ! str_contains(json_encode($context), '@');
    })->once();
});

test('sans aucun rôle admin, rien n\'est envoyé et le geste réussit', function () {
    Notification::fake();
    $city = City::factory()->create();

    Passport::actingAs(User::factory()->create());
    $this->postJson('/api/v1/characters', ['pseudo' => 'Artifice', 'city_id' => $city->id])->assertCreated();

    Notification::assertNothingSent();
});

test('l\'email rendu est en français, avec le bouton et la signature', function () {
    $admin = noticeAdmin();
    $notice = new AdminEventNotice('Nouveau personnage créé', ['Ligne un.'], 'Valider les personnages', 'https://odc-admin.example/characters');

    $mail = $notice->toMail($admin);
    $rendered = (string) $mail->render();

    expect($mail->subject)->toBe('Nouveau personnage créé')
        ->and($rendered)->toContain('Ligne un.')
        ->toContain('Valider les personnages')
        ->toContain('https://odc-admin.example/characters');
});
