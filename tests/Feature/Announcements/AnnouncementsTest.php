<?php

use App\Models\Role;
use App\Models\SiteAnnouncement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Annonces du site (18/11/2026)
|--------------------------------------------------------------------------
| L'administrateur prévient d'une maintenance : une annonce FR/EN, une échéance (date et heure précises
| OU période en jours) et une fenêtre de visibilité. L'accueil de l'application lit la seule annonce en
| cours par l'API publique et la fait défiler. « Maintenant » vaut mercredi 18/11/2026 10:00 UTC.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function announcementAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function announce(array $attributes = []): SiteAnnouncement
{
    return SiteAnnouncement::create(array_merge([
        'message_fr' => 'Maintenance prévue {quand}.',
        'message_en' => 'Maintenance planned {when}.',
        'visible_from' => Carbon::now()->subHour(),
        'is_active' => true,
    ], $attributes));
}

function currentAnnouncement(): ?array
{
    return test()->getJson('/api/v1/announcements/current')->assertOk()->json('announcement');
}

// ─── Schéma ──────────────────────────────────────────────────────────────────

test('la table ne porte aucun lien vers un compte ou un personnage', function () {
    expect(Schema::getColumnListing('site_announcements'))->toEqualCanonicalizing([
        'id', 'message_fr', 'message_en', 'schedule_type', 'maintenance_starts_at', 'maintenance_ends_at',
        'visible_from', 'visible_until', 'is_active', 'created_at', 'updated_at',
    ]);
});

// ─── API publique ────────────────────────────────────────────────────────────

test('sans annonce, l\'API répond null — sans jeton', function () {
    $this->getJson('/api/v1/announcements/current')
        ->assertOk()
        ->assertExactJson(['success' => true, 'announcement' => null])
        ->assertHeader('Cache-Control', 'max-age=60, public, stale-while-revalidate=60');
});

test('l\'annonce porte les deux langues, l\'échéance et la fin de visibilité, en UTC', function () {
    announce([
        'message_fr' => 'Fermeture bientôt.',
        'message_en' => 'Closing soon.',
        'schedule_type' => 'exact',
        'maintenance_starts_at' => '2026-11-24 21:00:00',
        'maintenance_ends_at' => '2026-11-24 22:30:00',
    ]);

    $announcement = currentAnnouncement();

    expect($announcement['message'])->toBe(['fr' => 'Fermeture bientôt.', 'en' => 'Closing soon.'])
        ->and($announcement['schedule'])->toBe([
            'type' => 'exact',
            'starts_at' => '2026-11-24T21:00:00Z',
            'ends_at' => '2026-11-24T22:30:00Z',
        ])
        ->and($announcement['visible_until'])->toBe('2026-11-24T22:30:00Z');
});

test('{quand} et {when} deviennent la date précise, dans la langue demandée (heure de Paris)', function () {
    announce([
        'schedule_type' => 'exact',
        'maintenance_starts_at' => '2026-11-24 21:00:00', // 22:00 à Paris
    ]);

    expect(currentAnnouncement()['message'])->toBe([
        'fr' => 'Maintenance prévue le 24 novembre à 22 h 00.',
        'en' => 'Maintenance planned on 24 November at 22:00.',
    ]);
});

test('une date précise avec une fin le même jour donne une plage horaire', function () {
    announce([
        'schedule_type' => 'exact',
        'maintenance_starts_at' => '2026-11-24 21:00:00',
        'maintenance_ends_at' => '2026-11-24 22:00:00',
    ]);

    expect(currentAnnouncement()['message']['fr'])->toBe('Maintenance prévue le 24 novembre de 22 h 00 à 23 h 00.');
});

test('une période de la semaine en cours se dit « cette semaine », la suivante « la semaine prochaine »', function () {
    // Paris : du lundi 16/11 00:00 (UTC+1) au dimanche 22/11 23:59:59.
    $thisWeek = announce(['schedule_type' => 'period', 'maintenance_starts_at' => '2026-11-15 23:00:00', 'maintenance_ends_at' => '2026-11-22 22:59:59']);

    expect(currentAnnouncement()['message'])->toBe([
        'fr' => 'Maintenance prévue cette semaine.',
        'en' => 'Maintenance planned this week.',
    ]);

    $thisWeek->delete();
    announce(['schedule_type' => 'period', 'maintenance_starts_at' => '2026-11-22 23:00:00', 'maintenance_ends_at' => '2026-11-29 22:59:59']);

    expect(currentAnnouncement()['message'])->toBe([
        'fr' => 'Maintenance prévue la semaine prochaine.',
        'en' => 'Maintenance planned next week.',
    ]);
});

test('une autre période se dit « du … au … », un seul jour « le … »', function () {
    $period = announce(['schedule_type' => 'period', 'maintenance_starts_at' => '2026-11-29 23:00:00', 'maintenance_ends_at' => '2026-12-02 22:59:59']);

    expect(currentAnnouncement()['message'])->toBe([
        'fr' => 'Maintenance prévue du 30 novembre au 2 décembre.',
        'en' => 'Maintenance planned from 30 November to 2 December.',
    ]);

    $period->delete();
    announce(['schedule_type' => 'period', 'maintenance_starts_at' => '2026-11-23 23:00:00', 'maintenance_ends_at' => '2026-11-24 22:59:59']);

    expect(currentAnnouncement()['message']['fr'])->toBe('Maintenance prévue le 24 novembre.');
});

test('un message sans marqueur reste tel quel, et sans échéance le marqueur reste aussi', function () {
    announce(['message_fr' => 'Rien de précis.', 'message_en' => 'Nothing precise.']);

    expect(currentAnnouncement()['message'])->toBe(['fr' => 'Rien de précis.', 'en' => 'Nothing precise.']);
});

test('l\'annonce n\'est pas visible avant son début, ni une fois terminée', function () {
    $later = announce(['visible_from' => Carbon::now()->addDay()]);
    expect(currentAnnouncement())->toBeNull();

    $later->update(['visible_from' => Carbon::now()->subDay(), 'visible_until' => Carbon::now()->subMinute()]);
    expect(currentAnnouncement())->toBeNull();

    $later->update(['visible_until' => Carbon::now()->addMinute()]);
    expect(currentAnnouncement())->not->toBeNull();
});

test('sans fin de visibilité, l\'annonce s\'arrête à la fin de la maintenance, ou à son début s\'il n\'y a pas de fin', function () {
    $a = announce(['schedule_type' => 'exact', 'maintenance_starts_at' => Carbon::now()->subHour(), 'maintenance_ends_at' => Carbon::now()->addHour()]);
    expect(currentAnnouncement())->not->toBeNull();

    $a->update(['maintenance_ends_at' => Carbon::now()->subMinute()]);
    expect(currentAnnouncement())->toBeNull();

    $a->update(['maintenance_starts_at' => Carbon::now()->addHour(), 'maintenance_ends_at' => null]);
    expect(currentAnnouncement())->not->toBeNull();

    $a->update(['maintenance_starts_at' => Carbon::now()->subMinute()]);
    expect(currentAnnouncement())->toBeNull();
});

test('sans aucune date, l\'annonce reste jusqu\'à ce qu\'on la retire', function () {
    $a = announce();
    expect(currentAnnouncement())->not->toBeNull();

    $a->update(['is_active' => false]);
    expect(currentAnnouncement())->toBeNull();
});

test('une seule annonce est servie : la maintenance la plus proche, une annonce sans date en dernier', function () {
    announce(['message_fr' => 'Sans date', 'message_en' => 'No date']);
    announce(['message_fr' => 'Loin', 'message_en' => 'Far', 'schedule_type' => 'exact', 'maintenance_starts_at' => Carbon::now()->addDays(10)]);
    announce(['message_fr' => 'Proche', 'message_en' => 'Near', 'schedule_type' => 'exact', 'maintenance_starts_at' => Carbon::now()->addDays(2)]);

    expect(currentAnnouncement()['message']['fr'])->toBe('Proche');
});

// ─── Administration ──────────────────────────────────────────────────────────

test('la page des annonces est réservée à l\'administrateur', function () {
    $this->get('/announcements')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/announcements')->assertForbidden();

    $this->actingAs(announcementAdmin())->get('/announcements')->assertOk()->assertSee(__('announcements.add_heading'));
});

test('une date précise saisie à Paris est stockée en UTC', function () {
    $this->actingAs(announcementAdmin())->post('/announcements', [
        'message_fr' => 'Maintenance {quand}.',
        'message_en' => 'Maintenance {when}.',
        'schedule_type' => 'exact',
        'exact_starts_at' => '2026-11-24T22:00',
        'exact_ends_at' => '2026-11-24T23:30',
    ])->assertRedirect('/announcements');

    $a = SiteAnnouncement::first();
    expect($a->schedule_type)->toBe('exact')
        ->and($a->maintenance_starts_at->utc()->toDateTimeString())->toBe('2026-11-24 21:00:00')
        ->and($a->maintenance_ends_at->utc()->toDateTimeString())->toBe('2026-11-24 22:30:00')
        ->and($a->visible_from->utc()->toDateTimeString())->toBe('2026-11-18 10:00:00') // « tout de suite »
        ->and($a->is_active)->toBeTrue();
});

test('une période va du premier jour 00:00 au dernier 23:59:59 (Paris), un seul jour par défaut', function () {
    $admin = announcementAdmin();

    $this->actingAs($admin)->post('/announcements', [
        'message_fr' => 'Semaine {quand}', 'message_en' => 'Week {when}',
        'schedule_type' => 'period', 'period_from' => '2026-11-16', 'period_to' => '2026-11-22',
    ])->assertRedirect();

    $week = SiteAnnouncement::latest('id')->first();
    expect($week->maintenance_starts_at->utc()->toDateTimeString())->toBe('2026-11-15 23:00:00')
        ->and($week->maintenance_ends_at->utc()->toDateTimeString())->toBe('2026-11-22 22:59:59');

    $this->actingAs($admin)->post('/announcements', [
        'message_fr' => 'Jour {quand}', 'message_en' => 'Day {when}',
        'schedule_type' => 'period', 'period_from' => '2026-11-24',
    ])->assertRedirect();

    $day = SiteAnnouncement::latest('id')->first();
    expect($day->maintenance_starts_at->utc()->toDateTimeString())->toBe('2026-11-23 23:00:00')
        ->and($day->maintenance_ends_at->utc()->toDateTimeString())->toBe('2026-11-24 22:59:59');
});

test('une annonce sans échéance se crée avec ses deux messages seulement', function () {
    $this->actingAs(announcementAdmin())->post('/announcements', [
        'message_fr' => 'Bientôt.', 'message_en' => 'Soon.', 'schedule_type' => '',
    ])->assertRedirect();

    $a = SiteAnnouncement::first();
    expect($a->schedule_type)->toBeNull()->and($a->maintenance_starts_at)->toBeNull();
});

test('les incohérences sont refusées', function (array $input, string $field) {
    $this->actingAs(announcementAdmin())
        ->post('/announcements', array_merge(['message_fr' => 'A', 'message_en' => 'B'], $input))
        ->assertSessionHasErrors($field);

    expect(SiteAnnouncement::count())->toBe(0);
})->with([
    'message FR absent' => [['message_fr' => ''], 'message_fr'],
    'message EN trop long' => [['message_en' => str_repeat('x', 501)], 'message_en'],
    'date précise sans début' => [['schedule_type' => 'exact'], 'exact_starts_at'],
    'fin avant le début' => [['schedule_type' => 'exact', 'exact_starts_at' => '2026-11-24T22:00', 'exact_ends_at' => '2026-11-24T21:00'], 'exact_ends_at'],
    'période sans premier jour' => [['schedule_type' => 'period'], 'period_from'],
    'dernier jour avant le premier' => [['schedule_type' => 'period', 'period_from' => '2026-11-24', 'period_to' => '2026-11-23'], 'period_to'],
    'visibilité finie avant son début' => [['visible_from' => '2026-11-20T10:00', 'visible_until' => '2026-11-19T10:00'], 'visible_until'],
    'type inconnu' => [['schedule_type' => 'jamais'], 'schedule_type'],
    'date mal formée' => [['schedule_type' => 'exact', 'exact_starts_at' => '24/11/2026'], 'exact_starts_at'],
]);

test('la modification relit les heures de Paris et enregistre les changements', function () {
    $admin = announcementAdmin();
    $a = announce(['schedule_type' => 'exact', 'maintenance_starts_at' => '2026-11-24 21:00:00']);

    $this->actingAs($admin)->get("/announcements/{$a->id}/edit")->assertOk()->assertSee('value="2026-11-24T22:00"', false);

    $this->actingAs($admin)->put("/announcements/{$a->id}", [
        'message_fr' => 'Reportée.', 'message_en' => 'Postponed.',
        'schedule_type' => 'period', 'period_from' => '2026-12-01', 'period_to' => '2026-12-03',
    ])->assertRedirect('/announcements');

    expect($a->fresh()->message_fr)->toBe('Reportée.')
        ->and($a->fresh()->schedule_type)->toBe('period')
        ->and($a->fresh()->maintenance_starts_at->utc()->toDateTimeString())->toBe('2026-11-30 23:00:00');
});

test('retirer puis remettre une annonce, ou la supprimer', function () {
    $admin = announcementAdmin();
    $a = announce();

    $this->actingAs($admin)->patch("/announcements/{$a->id}/toggle")->assertRedirect();
    expect($a->fresh()->is_active)->toBeFalse();
    expect(currentAnnouncement())->toBeNull();

    $this->actingAs($admin)->patch("/announcements/{$a->id}/toggle")->assertRedirect();
    expect($a->fresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->delete("/announcements/{$a->id}")->assertRedirect();
    expect(SiteAnnouncement::count())->toBe(0);
});

test('la liste montre le message tel que le verra un joueur francophone et son état', function () {
    $a = announce(['schedule_type' => 'exact', 'maintenance_starts_at' => '2026-11-24 21:00:00']);
    announce(['message_fr' => 'Ancienne.', 'is_active' => false]);

    $this->actingAs(announcementAdmin())->get('/announcements')
        ->assertOk()
        ->assertSee('Maintenance prévue le 24 novembre à 22 h 00.')
        ->assertSee(__('announcements.status.visible'))
        ->assertSee(__('announcements.status.off'));
});

test('seul l\'administrateur modifie : un compte ordinaire est refusé partout', function () {
    $user = User::factory()->create();
    $a = announce();

    $this->actingAs($user)->post('/announcements', ['message_fr' => 'A', 'message_en' => 'B'])->assertForbidden();
    $this->actingAs($user)->put("/announcements/{$a->id}", ['message_fr' => 'A', 'message_en' => 'B'])->assertForbidden();
    $this->actingAs($user)->patch("/announcements/{$a->id}/toggle")->assertForbidden();
    $this->actingAs($user)->delete("/announcements/{$a->id}")->assertForbidden();

    expect(SiteAnnouncement::count())->toBe(1)->and($a->fresh()->is_active)->toBeTrue();
});
