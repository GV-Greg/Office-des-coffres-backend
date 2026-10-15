<?php

use App\Models\CouncilMandate;
use App\Models\MineReport;
use App\Models\ProvinceMinePrice;
use App\Models\User;
use App\Notifications\MinePricesDigest;
use App\Services\AccountDeletion;
use App\Services\MinePrices;
use App\Support\MandateHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

/*
| Registre des mines, lot 2 — prix par période (brief admin/content/brief-prix-registre.md ;
| décisions de Greg des 14 et 15/10/2026). Trois postes égaux écrivent, le dirigeant lit seulement ;
| un prix se corrige jusqu'à 7 jours après la fin du poste qui le couvre ; résumé quotidien par email.
*/

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    // Les postes naissent à la validation (period.started_at = « maintenant ») : on valide le 03/05.
    atGameTime('2026-05-03 12:00');
    $this->province = $this->map['provinceA'];
    // Trois titulaires dans la province d'Artois (résidents de Berry : la province est celle du POSTE).
    $this->commerce = mandatePlayer($this->map['cityB'], ['pseudo' => 'Marchand']);
    $this->mines = mandatePlayer($this->map['cityB'], ['pseudo' => 'Mineur']);
    $this->bailli = mandatePlayer($this->map['cityB'], ['pseudo' => 'Bailli']);
    $this->comte = mandatePlayer($this->map['cityB'], ['pseudo' => 'Comte']);
    $this->commerceMandate = approvedCouncil($this->commerce, $this->province, '2026-05-01', '2026-05-03', 'commissaire_commerce');
    approvedCouncil($this->mines, $this->province, '2026-05-01', '2026-05-03', 'commissaire_mines');
    approvedCouncil($this->bailli, $this->province, '2026-05-01', '2026-05-03', 'bailli');
    approvedCouncil($this->comte, $this->province, '2026-05-01', '2026-05-03', 'leader');
    atGameTime('2026-05-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

function priceBody(array $overrides = []): array
{
    return $overrides + [
        'effective_from' => '2026-05-09',
        'prices' => ['OR' => 1.5, 'FER' => 2.25, 'PIERRE' => 20, 'ARGILE' => 3, 'SEL' => 4],
        'hourly_rate' => 0.7,
    ];
}

function pricesUrl($test, string $suffix = ''): string
{
    return "/api/v1/provinces/{$test->province->id}/mine-prices".$suffix;
}

function priceSet(array $attributes = []): ProvinceMinePrice
{
    return ProvinceMinePrice::factory()->create($attributes + ['province_id' => test()->province->id]);
}

// ─── Lecture ───────────────────────────────────────────────────────────────────────────

test('la liste est triée par date d\'effet décroissante, lisible par les trois postes et le dirigeant', function () {
    priceSet(['effective_from' => '2026-05-01']);
    priceSet(['effective_from' => '2026-05-08']);
    priceSet(['effective_from' => '2026-05-05']);

    foreach ([$this->commerce, $this->mines, $this->bailli, $this->comte] as $reader) {
        Passport::actingAs($reader->user);
        $this->getJson(pricesUrl($this))->assertOk()
            ->assertJsonPath('prices.0.effective_from', '2026-05-08')
            ->assertJsonPath('prices.1.effective_from', '2026-05-05')
            ->assertJsonPath('prices.2.effective_from', '2026-05-01');
    }
});

test('un utilisateur sans droit de lecture reçoit 403', function () {
    $stranger = mandatePlayer($this->map['cityA']);
    Passport::actingAs($stranger->user);

    $this->getJson(pricesUrl($this))->assertForbidden()->assertJsonPath('code', 'price_not_reader');
    $this->getJson(pricesUrl($this, '/current'))->assertForbidden();
});

test('un titulaire d\'une AUTRE province ne lit pas les prix de celle-ci', function () {
    $other = mandatePlayer($this->map['cityA']);
    approvedCouncil($other, $this->map['provinceB'], '2026-05-01', '2026-05-03', 'bailli');
    Passport::actingAs($other->user);

    $this->getJson(pricesUrl($this))->assertForbidden();
});

test('current renvoie le jeu en vigueur aujourd\'hui, null s\'il n\'y en a aucun', function () {
    Passport::actingAs($this->mines->user);
    $this->getJson(pricesUrl($this, '/current'))->assertOk()->assertJsonPath('price', null);

    priceSet(['effective_from' => '2026-05-01', 'price_or' => 9]);
    priceSet(['effective_from' => '2026-05-09', 'price_or' => 7]);
    priceSet(['effective_from' => '2026-12-01', 'price_or' => 1]);

    $this->getJson(pricesUrl($this, '/current'))->assertOk()
        ->assertJsonPath('price.effective_from', '2026-05-09')
        ->assertJsonPath('price.prices.OR', 7);
});

// ─── Création ──────────────────────────────────────────────────────────────────────────

test('les trois postes créent un jeu de prix (201), chacun nommé comme auteur', function () {
    foreach ([[$this->commerce, '2026-05-07'], [$this->mines, '2026-05-08'], [$this->bailli, '2026-05-09']] as [$author, $date]) {
        Passport::actingAs($author->user);
        $this->postJson(pricesUrl($this), priceBody(['effective_from' => $date]))->assertCreated()
            ->assertJsonPath('price.created_by', $author->pseudo)
            ->assertJsonPath('price.editable', true)
            ->assertJsonPath('price.prices.PIERRE', 20)
            ->assertJsonPath('price.hourly_rate', 0.7);
    }
    expect(ProvinceMinePrice::count())->toBe(3);
});

test('le dirigeant ne crée rien (403)', function () {
    Passport::actingAs($this->comte->user);
    $this->postJson(pricesUrl($this), priceBody())->assertForbidden()->assertJsonPath('code', 'price_not_authorized');
    expect(ProvinceMinePrice::count())->toBe(0);
});

test('un joueur sans mandat actif ne crée rien (403)', function () {
    $stranger = mandatePlayer($this->map['cityA']);
    Passport::actingAs($stranger->user);
    $this->postJson(pricesUrl($this), priceBody())->assertForbidden();
});

test('un second jeu à la même date est refusé (422)', function () {
    priceSet(['effective_from' => '2026-05-09']);
    Passport::actingAs($this->mines->user);

    $this->postJson(pricesUrl($this), priceBody())->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_exists');
});

test('la forme est validée : prix manquant, négatif ou à plus de deux décimales', function () {
    Passport::actingAs($this->mines->user);
    $body = priceBody();

    unset($body['prices']['SEL']);
    $this->postJson(pricesUrl($this), $body)->assertStatus(422)->assertJsonValidationErrors('prices.SEL');
    $this->postJson(pricesUrl($this), priceBody(['hourly_rate' => -1]))->assertStatus(422);
    $this->postJson(pricesUrl($this), priceBody(['hourly_rate' => 0.123]))->assertStatus(422);
});

test('un prix daté du futur est refusé', function () {
    Passport::actingAs($this->mines->user);
    $this->postJson(pricesUrl($this), priceBody(['effective_from' => '2026-05-11']))
        ->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_future');
});

test('un poste validé APRÈS l\'entrée en fonction couvre quand même le début du mandat (cas de prod du 16/10)', function () {
    // Le mandat court depuis le 03/05, mais l'Office ne connaît le poste que depuis le 08/05 (validation) : le
    // commissaire doit pouvoir dater un prix du 04/05, pas seulement à partir de sa validation.
    atGameTime('2026-05-03 12:00');
    $late = mandatePlayer($this->map['cityB'], ['pseudo' => 'Tardif']);
    $mandate = approvedCouncil($late, $this->map['provinceB'], '2026-05-01', '2026-05-03', null);
    atGameTime('2026-05-08 12:00');
    mandateWorkflow()->setOffice($mandate, 'bailli');
    atGameTime('2026-05-10 12:00');
    Passport::actingAs($late->user);
    $url = "/api/v1/provinces/{$this->map['provinceB']->id}/mine-prices";

    $this->postJson($url, priceBody(['effective_from' => '2026-05-04']))->assertCreated();
    // Le jour même de l'entrée en fonction compte aussi ; la veille, non.
    $this->postJson($url, priceBody(['effective_from' => '2026-05-03']))->assertCreated();
    $this->postJson($url, priceBody(['effective_from' => '2026-05-02']))
        ->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_outside_office');
});

test('la frontière des jours se joue en heure de Paris, pas en UTC', function () {
    // in_office_from = 03/05 00:00 Paris = 02/05 22:00 UTC : un prix du 02/05 (Paris) reste refusé, celui du 03/05 passe.
    Passport::actingAs($this->mines->user);
    $this->postJson(pricesUrl($this), priceBody(['effective_from' => '2026-05-02']))->assertStatus(422);
    $this->postJson(pricesUrl($this), priceBody(['effective_from' => '2026-05-03']))->assertCreated();
});

test('un prix antidaté AVANT l\'entrée en fonction de l\'auteur est refusé : le verrou ne se contourne pas', function () {
    Passport::actingAs($this->mines->user);
    $this->postJson(pricesUrl($this), priceBody(['effective_from' => '2026-04-20']))
        ->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_outside_office');
});

// ─── Modification ──────────────────────────────────────────────────────────────────────

test('un titulaire corrige un jeu de prix de sa période (200) ; l\'auteur de la correction est nommé', function () {
    $price = priceSet(['effective_from' => '2026-05-08', 'created_by_character_id' => $this->mines->id]);
    Passport::actingAs($this->bailli->user);

    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-08', 'hourly_rate' => 1.25]))
        ->assertOk()
        ->assertJsonPath('price.hourly_rate', 1.25)
        ->assertJsonPath('price.created_by', 'Mineur')
        ->assertJsonPath('price.updated_by', 'Bailli');
    expect($price->fresh()->hourly_rate)->toBe(1.25);
});

test('le dirigeant ne corrige rien (403)', function () {
    $price = priceSet(['effective_from' => '2026-05-08']);
    Passport::actingAs($this->comte->user);

    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody())->assertForbidden();
});

test('un poste pris APRÈS la date d\'effet ne corrige pas ce jeu, même en poste aujourd\'hui', function () {
    $price = priceSet(['effective_from' => '2026-05-02']); // avant l'entrée en fonction du 03/05
    Passport::actingAs($this->mines->user);

    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-02']))
        ->assertForbidden()->assertJsonPath('code', 'price_locked');
});

test('un titulaire dont le poste est terminé corrige encore pendant 7 jours, puis le jeu est verrouillé', function () {
    $price = priceSet(['effective_from' => '2026-05-20']);
    $end = CouncilMandate::where('character_id', $this->commerce->id)->sole()->holds_until;

    Carbon::setTestNow($end->copy()->addDays(5));
    Passport::actingAs($this->commerce->user);
    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-20']))->assertOk();

    Carbon::setTestNow($end->copy()->addDays(8));
    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-20']))
        ->assertForbidden()->assertJsonPath('code', 'price_locked');
});

test('la durée de grâce vient de la configuration', function () {
    $price = priceSet(['effective_from' => '2026-05-20']);
    $end = CouncilMandate::where('character_id', $this->commerce->id)->sole()->holds_until;
    Passport::actingAs($this->commerce->user);

    config(['mandates.price_edit_grace_days' => 2]);
    Carbon::setTestNow($end->copy()->addDays(3));
    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-20']))->assertForbidden();

    config(['mandates.price_edit_grace_days' => 10]);
    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-20']))->assertOk();
});

test('un mandat révoqué ne donne plus aucun droit de correction', function () {
    $price = priceSet(['effective_from' => '2026-05-08']);
    CouncilMandate::where('character_id', $this->mines->id)->update(['status' => CouncilMandate::REVOKED]);
    Passport::actingAs($this->mines->user);

    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-08']))->assertForbidden();
});

test('changer la date d\'un jeu : refusée si elle est déjà prise', function () {
    $price = priceSet(['effective_from' => '2026-05-08']);
    priceSet(['effective_from' => '2026-05-09']);
    Passport::actingAs($this->mines->user);

    $this->putJson(pricesUrl($this, "/{$price->id}"), priceBody(['effective_from' => '2026-05-09']))
        ->assertStatus(422)->assertJsonPath('codes.effective_from', 'price_exists');
});

test('la liste dit, jeu par jeu, si LE LECTEUR peut le corriger', function () {
    priceSet(['effective_from' => '2026-05-08']);
    priceSet(['effective_from' => '2026-05-02']);

    Passport::actingAs($this->mines->user);
    $this->getJson(pricesUrl($this))->assertJsonPath('prices.0.editable', true)->assertJsonPath('prices.1.editable', false);

    Passport::actingAs($this->comte->user);
    $this->getJson(pricesUrl($this))->assertJsonPath('prices.0.editable', false);
});

test('aucune route de suppression', function () {
    $price = priceSet();
    Passport::actingAs($this->mines->user);
    $this->deleteJson(pricesUrl($this, "/{$price->id}"))->assertStatus(405);
});

test('le débit des écritures est limité à 10 par minute', function () {
    Passport::actingAs($this->mines->user);
    foreach (range(1, 10) as $i) {
        $this->postJson(pricesUrl($this), priceBody(['effective_from' => '2026-05-09', 'hourly_rate' => 'x']))->assertStatus(422);
    }
    $this->postJson(pricesUrl($this), priceBody())->assertStatus(429);
});

// ─── Service ───────────────────────────────────────────────────────────────────────────

test('getPriceForDate renvoie le jeu le plus récent dont la date d\'effet précède ou égale le jour', function () {
    priceSet(['effective_from' => '2026-01-01', 'price_or' => 1]);
    priceSet(['effective_from' => '2026-01-15', 'price_or' => 2]);
    $service = app(MinePrices::class);

    expect($service->getPriceForDate($this->province->id, '2026-01-10')->price_or)->toBe(1.0)
        ->and($service->getPriceForDate($this->province->id, '2026-01-15')->price_or)->toBe(2.0)
        ->and($service->getPriceForDate($this->province->id, '2026-01-20')->price_or)->toBe(2.0)
        ->and($service->getPriceForDate($this->province->id, '2025-12-31'))->toBeNull()
        ->and($service->getPriceForDate($this->map['provinceB']->id, '2026-01-20'))->toBeNull();
});

// ─── Relevé : les prix en vigueur sont figés dans le payload ──────────────────────────

test('un relevé inscrit fige les prix en vigueur à sa date ; sans table, il garde ceux que le client envoie', function () {
    Passport::actingAs($this->mines->user);
    $report = ['mines' => [['number' => 1, 'noeud' => '236', 'days' => ['2026-05-09' => ['heures' => 5, 'production' => 10]]]]];
    $body = ['raw' => 'x', 'report' => $report, 'prices' => ['OR' => 99], 'rate' => 9];

    $this->postJson("/api/v1/characters/{$this->mines->id}/mine-reports", $body)->assertCreated();
    expect(MineReport::sole()->payload['prices'])->toBe(['OR' => 99])->and(MineReport::sole()->payload['rate'])->toBe(9);

    MineReport::query()->delete();
    priceSet(['effective_from' => '2026-05-01', 'price_or' => 1.5, 'hourly_rate' => 0.7]);
    $this->postJson("/api/v1/characters/{$this->mines->id}/mine-reports", ['raw' => 'x', 'report' => $report])->assertCreated();
    $payload = MineReport::sole()->payload;
    expect($payload['prices']['OR'])->toBe(1.5)->and($payload['rate'])->toBe(0.7);
});

test('l\'accès au registre dit où les prix s\'écrivent et se lisent', function () {
    Passport::actingAs($this->commerce->user);
    $this->getJson("/api/v1/characters/{$this->commerce->id}/mine-registry")
        ->assertOk()
        ->assertJsonPath('prices.write.id', $this->province->id)
        ->assertJsonPath('prices.read.id', $this->province->id)
        ->assertJsonPath('write', null);

    Passport::actingAs($this->comte->user);
    $this->getJson("/api/v1/characters/{$this->comte->id}/mine-registry")
        ->assertJsonPath('prices.write', null)->assertJsonPath('prices.read.id', $this->province->id);
});

// ─── Résumé quotidien ─────────────────────────────────────────────────────────────────

test('le résumé prévient les deux AUTRES postes, jamais l\'auteur, et rien de plus', function () {
    atGameTime('2026-05-10 15:00');
    Cache::flush();
    Passport::actingAs($this->mines->user);
    $this->postJson(pricesUrl($this), priceBody())->assertCreated();

    Artisan::call('mines:prices-digest');

    Notification::assertSentTo([$this->commerce->user, $this->bailli->user], MinePricesDigest::class);
    Notification::assertNotSentTo($this->mines->user, MinePricesDigest::class);
    Notification::assertNotSentTo($this->comte->user, MinePricesDigest::class);
    Notification::assertSentTimes(MinePricesDigest::class, 2);
});

test('un résumé nomme l\'auteur et dit s\'il s\'agit d\'une création ou d\'une modification, en FR puis en EN', function () {
    atGameTime('2026-05-10 15:00');
    Cache::flush();
    Passport::actingAs($this->mines->user);
    $id = $this->postJson(pricesUrl($this), priceBody())->json('price.id');
    Artisan::call('mines:prices-digest');
    atGameTime('2026-05-11 15:00');
    Passport::actingAs($this->bailli->user);
    $this->putJson(pricesUrl($this, "/{$id}"), priceBody(['effective_from' => '2026-05-09', 'hourly_rate' => 1]))->assertOk();
    Artisan::call('mines:prices-digest');

    $sent = Notification::sent($this->commerce->user, MinePricesDigest::class);
    $lines = $sent->last()->lines();
    expect($sent)->toHaveCount(2)
        ->and(implode("\n", $lines))->toContain('modifiés par Bailli')->toContain('updated by Bailli')->toContain('———');
    // Le bailli, auteur de la correction, ne reçoit pas SA modification ; le commissaire aux mines, si.
    Notification::assertSentToTimes($this->bailli->user, MinePricesDigest::class, 1);
    Notification::assertSentToTimes($this->mines->user, MinePricesDigest::class, 1);
});

test('aucun changement, aucun email — et le passage est tout de même enregistré', function () {
    atGameTime('2026-05-10 15:00');
    Cache::flush();
    priceSet(['effective_from' => '2026-05-01', 'created_at' => '2026-04-01', 'updated_at' => '2026-04-01']);

    Artisan::call('mines:prices-digest');

    Notification::assertNotSentTo([$this->commerce->user, $this->mines->user, $this->bailli->user], MinePricesDigest::class);
    expect(MandateHeartbeat::lastSuccess('prices'))->not->toBeNull();
});

test('un même changement n\'est jamais renvoyé le lendemain', function () {
    atGameTime('2026-05-10 15:00');
    Cache::flush();
    Passport::actingAs($this->mines->user);
    $this->postJson(pricesUrl($this), priceBody())->assertCreated();

    Artisan::call('mines:prices-digest');
    atGameTime('2026-05-11 15:00');
    Artisan::call('mines:prices-digest');

    Notification::assertSentTimes(MinePricesDigest::class, 2);
});

test('un poste vacant ou un titulaire sans compte ne produit aucune notification fantôme', function () {
    atGameTime('2026-05-10 15:00');
    Cache::flush();
    CouncilMandate::where('character_id', $this->bailli->id)->update(['holds_until' => Carbon::now()->subDay()]);
    Passport::actingAs($this->mines->user);
    $this->postJson(pricesUrl($this), priceBody())->assertCreated();

    Artisan::call('mines:prices-digest');

    Notification::assertSentTo($this->commerce->user, MinePricesDigest::class);
    Notification::assertNotSentTo($this->bailli->user, MinePricesDigest::class);
    Notification::assertSentTimes(MinePricesDigest::class, 1);
});

test('le résumé est planifié à 15:00, fuseau de Paris', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'mines:prices-digest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 15 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});

// ─── Cycle de vie ─────────────────────────────────────────────────────────────────────

test('la suppression d\'un compte coupe le lien vers l\'auteur ; le prix reste', function () {
    $price = priceSet(['created_by_character_id' => $this->mines->id, 'updated_by_character_id' => $this->bailli->id]);

    app(AccountDeletion::class)->deleteUser(User::find($this->mines->user_id));

    $price = $price->fresh();
    expect($price)->not->toBeNull()
        ->and($price->created_by_character_id)->toBeNull()
        ->and($price->updated_by_character_id)->toBe($this->bailli->id);
});
