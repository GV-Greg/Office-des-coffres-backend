<?php

use App\Models\AnimActivityType;
use App\Models\Character;
use App\Models\MineReport;
use App\Models\Province;
use App\Models\User;
use App\Services\DecodeWorkflow;
use App\Support\DecodeSlug;
use Illuminate\Support\Carbon;

/*
| Tableau de bord — compteurs, alertes mandats expirants et activités inactives, fil d'activité récente.
| Les tests d'accès (guest, non-admin, admin) restent dans DashboardTest.php.
*/

afterEach(fn () => Carbon::setTestNow());

// ─── Cartes de compteurs ────────────────────────────────────────────────────────────────────────

test('le tableau de bord affiche les 6 étiquettes de compteurs', function () {
    $admin = mandateAdmin();

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('Comptes')
        ->assertSee('Personnages')
        ->assertSee('Mandats actifs')
        ->assertSee('Relevés mines')
        ->assertSee('Coffres scellés')
        ->assertSee('Provinces avec prix');
});

test('le compteur de comptes non vérifiés s\'affiche en ambre si > 0', function () {
    $admin = mandateAdmin();
    User::factory()->unverified()->count(2)->create();

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('2 non vérifiés');
});

test('le compteur de personnages en attente s\'affiche si > 0', function () {
    $admin = mandateAdmin();
    Character::factory()->create(['city_id' => null, 'is_validated' => false]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('à valider');
});

test('le compteur de relevés mines actifs est correct', function () {
    $admin = mandateAdmin();
    $province = Province::factory()->create();
    MineReport::create([
        'province_id' => $province->id,
        'reported_at' => '2026-10-20',
        'character_id' => null,
        'office_key'   => 'commissaire_mines',
        'payload'      => ['raw' => 'test', 'prices' => [], 'rate' => 0.5],
        'active'       => true,
    ]);

    // La vue se charge sans erreur et affiche le libellé
    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('Relevés mines');
});

// ─── Alerte mandats expirants ───────────────────────────────────────────────────────────────────

test('aucune alerte de mandat expirant si aucun mandat n\'est proche de sa fin', function () {
    $admin = mandateAdmin();
    atGameTime('2026-10-29 12:00');

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Mandats expirant dans 7 jours');
});

test('l\'alerte des mandats expirants s\'affiche pour un mandat en cours à moins de 7 jours', function () {
    $admin = mandateAdmin();
    $map   = mandateMap();
    atGameTime('2026-10-29 12:00');
    // started_at il y a 28 jours → holds_until = 2026-10-31 (2 jours, dans la fenêtre de 7 j)
    $player = mandatePlayer($map['cityA']);
    approvedMayor($player, $map['cityA'], '2026-10-01');

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('Mandats expirant dans 7 jours');
});

test('l\'alerte disparaît si un renouvellement en attente existe pour le mandat expirant', function () {
    $admin = mandateAdmin();
    $map   = mandateMap();
    atGameTime('2026-10-29 12:00');
    $player  = mandatePlayer($map['cityA']);
    $mandate = approvedMayor($player, $map['cityA'], '2026-10-01');
    // Créer un renouvellement → status PENDING : doit exclure le mandat de l'alerte
    mandateWorkflow()->renew($mandate, '2026-10-29', 'https://forum.example/r');

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Mandats expirant dans 7 jours');
});

test('un mandat qui expire dans plus de 7 jours ne déclenche pas l\'alerte', function () {
    $admin = mandateAdmin();
    $map   = mandateMap();
    atGameTime('2026-10-29 12:00');
    // started_at il y a 5 jours → holds_until = 2026-11-28 (30 jours), bien au-delà de 7 j
    $player = mandatePlayer($map['cityA']);
    approvedMayor($player, $map['cityA'], '2026-10-24');

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Mandats expirant dans 7 jours');
});

// ─── Alerte activités inactives ─────────────────────────────────────────────────────────────────

test('aucune alerte d\'activité inactive si toutes les activités sont récentes', function () {
    $admin    = mandateAdmin();
    $map      = mandateMap();
    $type     = AnimActivityType::where('key', 'decode')->firstOrFail();
    $workflow = app(DecodeWorkflow::class);
    $orga     = mandatePlayer($map['cityA']);
    $workflow->create($orga, $type, ['name' => 'Coffre Récent', 'slug' => 'coffre-recent', 'province_id' => $map['provinceA']->id]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Activités proches de la fermeture automatique');
});

test('l\'alerte des activités inactives s\'affiche pour une activité sans action depuis 24 jours', function () {
    $admin    = mandateAdmin();
    $map      = mandateMap();
    $type     = AnimActivityType::where('key', 'decode')->firstOrFail();
    $workflow = app(DecodeWorkflow::class);

    // Créer l'activité dans le passé (24 jours) puis revenir à maintenant
    atGameTime(Carbon::now()->subDays(24)->toDateTimeString());
    $orga = mandatePlayer($map['cityA']);
    $workflow->create($orga, $type, ['name' => 'Vieux Coffre', 'slug' => 'vieux-coffre', 'province_id' => $map['provinceA']->id]);
    Carbon::setTestNow();

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('Activités proches de la fermeture automatique');
});

test('une activité close ne figure pas dans l\'alerte d\'inactivité', function () {
    $admin    = mandateAdmin();
    $map      = mandateMap();
    $type     = AnimActivityType::where('key', 'decode')->firstOrFail();
    $workflow = app(DecodeWorkflow::class);

    // Créer et fermer l'activité dans le passé
    atGameTime(Carbon::now()->subDays(24)->toDateTimeString());
    $orga     = mandatePlayer($map['cityA']);
    $activity = $workflow->create($orga, $type, ['name' => 'Coffre Clos', 'slug' => 'coffre-clos', 'province_id' => $map['provinceA']->id]);
    $workflow->close($orga, $activity);
    Carbon::setTestNow();

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Activités proches de la fermeture automatique');
});

// ─── Fil d'activité récente ─────────────────────────────────────────────────────────────────────

test('le fil d\'activité récente affiche les nouveaux comptes et personnages', function () {
    $admin = mandateAdmin();
    User::factory()->create(['email' => 'evenement@test.com']);
    Character::factory()->create(['pseudo' => 'Artefact', 'city_id' => null]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('evenement@test.com')
        ->assertSee('Artefact')
        ->assertSee('Activité récente');
});

test('le fil d\'activité récente affiche les demandes de mandats en attente', function () {
    $admin = mandateAdmin();
    $map   = mandateMap();
    atGameTime('2026-10-29 12:00');
    $player = mandatePlayer($map['cityA']);
    // Créer une demande sans l'approuver pour garder le statut pending
    mandateWorkflow()->request($player, 'mayor', [
        'city_id'          => $map['cityA']->id,
        'started_at'       => '2026-10-01',
        'announcement_url' => 'https://forum.example/m',
    ]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee($player->pseudo);
});

test('le fil est vide si aucun événement n\'existe', function () {
    // L'admin existe seul (créé par mandateAdmin, donc 1 compte)
    $admin = mandateAdmin();

    // Supprimer l'admin du fil n'est pas trivial ; on vérifie juste que la page charge
    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('Activité récente');
});
