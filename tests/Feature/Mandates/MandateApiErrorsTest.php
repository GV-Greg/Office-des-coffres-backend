<?php

use App\Exceptions\MandateRefusal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

// Réponses d'erreur de l'API des mandats (fil admin/echanges/mandats-lot2, Q1, Q6, Q8, Q9, Q10, Q11).
// Trois garde-fous, tous dans ce dépôt et en CI :
//  1. tout refus de MandateWorkflow porte un code (statique) ;
//  2. toute réponse d'erreur de l'API des mandats est complète : code + messages FR et EN (frontière) ;
//  3. l'API n'émet jamais un code `admin.`.

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
    atGameTime('2026-05-10 12:00');
    $this->character = mandatePlayer($this->map['cityA']);
    Passport::actingAs($this->character->user);
});

afterEach(fn () => Carbon::setTestNow());

/** Vérifie qu'une réponse d'erreur est complète, et la renvoie. */
function assertCompleteError(TestResponse $response): TestResponse
{
    $status = $response->getStatusCode();
    expect($status)->toBeGreaterThanOrEqual(400);

    if ($status === 422) {
        $errors = $response->json('errors');
        expect($errors)->not->toBeEmpty();
        foreach ($errors as $field => $list) {
            $code = $response->json("codes.{$field}");
            $fr = $response->json("messages.{$field}.fr");
            $en = $response->json("messages.{$field}.en");
            expect($code)->toBeString()->not->toStartWith('admin.')
                ->and($fr)->toBe($list[0])                       // la duplication ne peut pas dériver
                ->and($en)->toBeString()->not->toBe('')
                ->and($en)->not->toContain('mandates.')->not->toStartWith('validation.');
        }
    } else {
        expect($response->json('code'))->toBeString()->not->toStartWith('admin.')
            ->and($response->json('messages.fr'))->toBe($response->json('message'))
            ->and($response->json('messages.en'))->toBeString()->not->toContain('mandates.');
    }

    return $response;
}

// ─── 1. Statique : tout refus porte un code ────────────────────────────────────────────────

test('tout refus de MandateWorkflow passe par refuse(), donc porte un code', function () {
    $source = file_get_contents(app_path('Services/MandateWorkflow.php'));

    expect($source)->not->toContain('withMessages(')
        ->and($source)->not->toContain('new ValidationException');

    preg_match_all("/refuse\\('[a-z_]+', '([a-z_.]+)'/", $source, $literal);
    preg_match_all("/refuse\\([^,]+, \\\$admin \\? '([a-z_.]+)' : '([a-z_.]+)'/", $source, $dynamic);
    $keys = array_unique([...$literal[1], ...$dynamic[1], ...$dynamic[2]]);

    expect(count($keys))->toBeGreaterThan(20);
    foreach ($keys as $key) {
        expect($key)->toMatch('/^mandates\.(api\.[a-z_]+|admin\.[a-z_.]+)$/')
            ->and(__($key, [], 'fr'))->not->toBe($key);
        if (str_starts_with($key, 'mandates.api.')) {
            expect(__($key, [], 'en'))->not->toBe($key); // un refus joueur a toujours son anglais
        }
    }
});

// ─── 2. Frontière : toute réponse d'erreur est complète ────────────────────────────────────

test('les erreurs de forme (Q10) portent code et messages FR/EN, avec des noms de champs lisibles', function () {
    $response = assertCompleteError($this->postJson("/api/v1/characters/{$this->character->id}/mandates", [
        'level' => 'mayor', 'started_at' => 'pas-une-date', 'announcement_url' => 'http://forum.example/a',
    ]))->assertStatus(422);

    expect($response->json('codes.city_id'))->toBe('validation.required_if')
        ->and($response->json('codes.announcement_url'))->toBe('validation.starts_with')
        ->and($response->json('messages.started_at.en'))->toContain('start date');
});

test('les refus métier portent leur code, et le message anglais du mort-né', function () {
    $response = assertCompleteError($this->postJson("/api/v1/characters/{$this->character->id}/mandates", [
        'level' => 'mayor', 'city_id' => $this->map['cityA']->id, 'started_at' => '2026-03-01',
        'announcement_url' => 'https://forum.example/a',
    ]))->assertStatus(422);

    expect($response->json('codes.started_at'))->toBe('dead_born')
        ->and($response->json('messages.started_at.en'))->toBe(__('mandates.api.dead_born', [], 'en'));
});

test('toute la batterie de refus de l\'API est complète', function () {
    $other = mandatePlayer($this->map['cityB']);
    $mayor = approvedMayor($this->character, $this->map['cityA'], '2026-05-01');
    $elected = approvedCouncil($this->character, $this->map['provinceA'], '2026-05-09');
    $base = "/api/v1/characters/{$this->character->id}/mandates";

    $responses = [
        $this->postJson($base, []),                                                         // tout manque
        $this->postJson($base, ['level' => 'mayor', 'city_id' => 999999, 'started_at' => '2026-05-01', 'announcement_url' => 'https://a.example']),
        $this->postJson($base, ['level' => 'mayor', 'city_id' => $this->map['cityA2']->id, 'province_id' => 1, 'started_at' => '2026-05-01', 'announcement_url' => 'https://a.example']),
        $this->postJson($base, ['level' => 'mayor', 'city_id' => $this->map['cityA2']->id, 'started_at' => '2026-05-11', 'announcement_url' => 'https://a.example']),
        $this->postJson($base, ['level' => 'mayor', 'city_id' => $this->map['cityA2']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://a.example']), // already_holding
        $this->postJson("/api/v1/characters/{$other->id}/mandates", ['level' => 'mayor']),     // 404 personnage
        $this->deleteJson("/api/v1/mandates/mayor/{$mayor->id}"),                             // not_pending
        $this->deleteJson('/api/v1/mandates/mayor/999999'),                                   // 404 mandat
        $this->postJson("/api/v1/mandates/council/{$elected->id}/office", ['council_office_key' => 'juge']), // élu : pas de poste
        $this->postJson("/api/v1/mandates/council/{$elected->id}/office", ['council_office_key' => 'inconnu']),
    ];

    foreach ($responses as $response) {
        assertCompleteError($response);
    }
});

test('le 429 du limiteur porte son code et son message anglais', function () {
    $url = "/api/v1/characters/{$this->character->id}/mandates";
    foreach (range(1, 6) as $i) {
        $this->postJson($url, []);
    }

    $response = assertCompleteError($this->postJson($url, []))->assertStatus(429);
    expect($response->json('code'))->toBe('throttled')
        ->and($response->json('messages.en'))->toStartWith('Too many attempts');
});

// ─── 3. L'API n'émet jamais un code admin. ─────────────────────────────────────────────────

test('un refus d\'administration routé vers l\'API échoue bruyamment au lieu d\'atteindre un joueur', function () {
    Route::middleware('api')->post('api/v1/mandates/test-frontiere', fn () => throw MandateRefusal::make('mandate', 'mandates.admin.errors.not_pending'));

    $this->postJson('/api/v1/mandates/test-frontiere')->assertStatus(500);
});

test('contrôle positif de la frontière : le même refus côté joueur passe', function () {
    Route::middleware('api')->post('api/v1/mandates/test-frontiere', fn () => throw MandateRefusal::make('mandate', 'mandates.api.not_pending'));

    assertCompleteError($this->postJson('/api/v1/mandates/test-frontiere'))->assertStatus(422)
        ->assertJsonPath('codes.mandate', 'not_pending');
});

// ─── Q6 : can_request et blocked_by ────────────────────────────────────────────────────────

test('can_request dit pourquoi un personnage ne peut pas demander, par niveau', function () {
    $unvalidated = mandatePlayer($this->map['cityA2'], ['user_id' => $this->character->user_id, 'is_validated' => false]);
    mandateWorkflow()->request($this->character, 'mayor', [
        'city_id' => $this->map['cityA']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/a',
    ]);
    approvedCouncil($this->character, $this->map['provinceA'], '2026-05-09');

    $characters = collect($this->getJson('/api/v1/mandates')->assertOk()->json('characters'))->keyBy('id');

    expect($characters[$this->character->id]['requestable']['mayor'])->toBe(['can_request' => false, 'blocked_by' => 'pending'])
        ->and($characters[$this->character->id]['requestable']['council'])->toBe(['can_request' => false, 'blocked_by' => 'elected'])
        ->and($characters[$unvalidated->id]['requestable']['mayor'])->toBe(['can_request' => false, 'blocked_by' => 'not_validated']);
});

test('Q7 — après une expiration, on peut à la fois redemander et renouveler : rien n\'est masqué', function () {
    $mandate = approvedMayor($this->character, $this->map['cityA'], '2026-05-01');
    Carbon::setTestNow($mandate->holds_until->copy()->addDays(3));

    $response = $this->getJson('/api/v1/mandates')->assertOk();

    expect($response->json('characters.0.requestable.mayor'))->toBe(['can_request' => true, 'blocked_by' => null])
        ->and($response->json('mandates.0.renewable'))->toBeTrue();
});

// ─── Q11 : les libellés portés par le backend ──────────────────────────────────────────────

test('les postes, motifs et causes de fin arrivent avec leurs libellés FR et EN', function () {
    $offices = collect($this->getJson('/api/v1/council-offices')->json('offices'))->keyBy('key');
    expect($offices['connetable']['label'])->toBe(['fr' => 'Connétable', 'en' => 'Sergeant']);

    $mandate = approvedCouncil($this->character, $this->map['provinceA'], '2026-05-01', '2026-05-03', 'juge');
    mandateWorkflow()->setOffice($mandate, null, 'retire_par_le_dirigeant');
    $refused = mandateWorkflow()->request($this->character, 'mayor', [
        'city_id' => $this->map['cityA']->id, 'started_at' => '2026-05-01', 'announcement_url' => 'https://forum.example/a',
    ]);
    mandateWorkflow()->reject($refused, 'date_incoherente', null, null);

    $mandates = collect($this->getJson('/api/v1/mandates')->json('mandates'))->keyBy(fn ($m) => $m['level'].':'.$m['id']);
    $council = $mandates["council:{$mandate->id}"];
    expect($council['office_label'])->toBe(['fr' => 'sans poste', 'en' => 'without an office'])
        ->and($council['office_history'][0]['office_label']['en'])->toBe('Judge')
        ->and($council['office_history'][0]['end_reason_label']['en'])->toBe(__('mandates.reasons.retire_par_le_dirigeant', [], 'en'))
        ->and($mandates["mayor:{$refused->id}"]['decision_reason_label']['en'])->toBe(__('mandates.reasons.date_incoherente', [], 'en'))
        ->and($council)->not->toHaveKey('status_label');
});
