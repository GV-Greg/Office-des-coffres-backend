<?php

use App\Exceptions\MandateRefusal;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;
use Illuminate\Support\Carbon;

/*
| Les Coffres Scellés — gestes de l'organisateur (brief admin/modules/animation/brief-coffres-scelles.md ;
| décisions de Greg du tour 03 : suppression d'une activité CLOSE seulement, réouverture refusée si une
| autre activité du type est active). Autorisation par PROPRIÉTÉ.
*/

beforeEach(function () {
    $this->map = mandateMap();
    $this->type = AnimActivityType::where('key', 'decode')->firstOrFail();
    $this->orga = mandatePlayer($this->map['cityA']);
    $this->other = mandatePlayer($this->map['cityB']);
    $this->workflow = app(DecodeWorkflow::class);
    $this->proposals = app(DecodeProposals::class);
});

afterEach(fn () => Carbon::setTestNow());

function decodeActivity($test, string $name = 'Coffre des Marches', array $extra = []): AnimDecodeActivity
{
    return $test->workflow->create($test->orga, $test->type, ['name' => $name] + $extra);
}

function refusal(callable $call): MandateRefusal
{
    try {
        $call();
    } catch (MandateRefusal $e) {
        return $e;
    }
    throw new RuntimeException('Un refus était attendu.');
}

test('une activité se crée neuve, rattachée à son organisateur, sans coffre', function () {
    $activity = decodeActivity($this, extra: ['description' => 'Pour la fête', 'province_id' => $this->map['provinceA']->id]);

    expect($activity->status)->toBe('new')
        ->and($activity->character_id)->toBe($this->orga->id)
        ->and($activity->launched_at)->toBeNull()
        ->and($activity->secrets()->count())->toBe(0);
});

test('province et ville s\'excluent', function () {
    $e = refusal(fn () => decodeActivity($this, extra: ['province_id' => $this->map['provinceA']->id, 'city_id' => $this->map['cityA']->id]));

    expect($e->codes)->toBe(['city_id' => 'place_exclusive']);
});

test('un type désactivé ne reçoit plus d\'activité', function () {
    $this->type->update(['is_active' => false]);

    expect(refusal(fn () => decodeActivity($this))->codes)->toBe(['type' => 'type_inactive']);
});

test('une seule activité non close du même type par personnage, mais pas de limite pour le total', function () {
    decodeActivity($this);

    expect(refusal(fn () => decodeActivity($this, 'Second'))->codes)->toBe(['name' => 'activity_already_active']);

    // Un autre personnage a les siennes ; une fois la première close, le premier peut en créer une autre.
    $this->workflow->create($this->other, $this->type, ['name' => 'Celui de l\'autre']);
    $this->workflow->close($this->orga, AnimDecodeActivity::where('name', 'Coffre des Marches')->first());
    expect(decodeActivity($this, 'Second')->status)->toBe('new');
});

test('le premier coffre passe l\'activité en cours et pose launched_at une fois', function () {
    atGameTime('2026-10-01 10:00');
    $activity = decodeActivity($this);

    $secret = $this->workflow->generateSecret($this->orga, $activity);

    expect($secret->code)->toMatch('/^\d{5}$/')
        ->and($secret->status)->toBeFalse()
        ->and($activity->refresh()->status)->toBe('launched')
        ->and($activity->launched_at->toDateTimeString())->not->toBeNull();
    // Le code est CHIFFRÉ en base (MODULE_DATA_KEY), jamais en clair.
    expect(DB::table('anim_decode_secrets')->value('code'))->not->toBe($secret->code);
});

test('pas de second coffre tant que le premier n\'est pas percé', function () {
    $activity = decodeActivity($this);
    $this->workflow->generateSecret($this->orga, $activity);

    expect(refusal(fn () => $this->workflow->generateSecret($this->orga, $activity))->codes)->toBe(['activity' => 'secret_pending']);
});

test('REFUS 403 : un autre personnage ne gère rien — coffre, proposition, réouverture, clôture, suppression', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);

    foreach ([
        fn () => $this->workflow->generateSecret($this->other, $activity),
        fn () => $this->proposals->submit($this->other, $activity, $secret, '12345', 'Quelqu\'un'),
        fn () => $this->workflow->reopen($this->other, $activity),
        fn () => $this->workflow->close($this->other, $activity),
        fn () => $this->workflow->delete($this->other, $activity),
    ] as $gesture) {
        $e = refusal($gesture);
        expect($e->status)->toBe(403)->and($e->codes)->toBe(['activity' => 'not_owner']);
    }
});

test('une proposition est notée ; un code trouvé vaut 20, perce le coffre et en scelle un nouveau', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);
    $code = $secret->code;

    $miss = $this->proposals->submit($this->orga, $activity, $secret, '00000', 'Aldric');
    expect($miss['found'])->toBeFalse()->and($miss['next'])->toBeNull();

    $hit = $this->proposals->submit($this->orga, $activity, $secret, $code, 'Brunehaut');

    expect($hit['found'])->toBeTrue()
        ->and($hit['score'])->toBe(10)
        ->and($hit['proposal']->points)->toBe(20)
        ->and($secret->refresh()->status)->toBeTrue()
        ->and($hit['next'])->not->toBeNull()
        ->and($hit['next']->status)->toBeFalse()
        ->and($activity->refresh()->status)->toBe('relaunch')
        ->and(AnimDecodeProposal::count())->toBe(2);
});

test('une proposition mal formée, ou sur un coffre déjà percé, est refusée', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);

    expect(refusal(fn () => $this->proposals->submit($this->orga, $activity, $secret, '123', 'X'))->codes)->toBe(['combination' => 'combination_invalid']);

    $this->proposals->submit($this->orga, $activity, $secret, $secret->code, 'X');
    expect(refusal(fn () => $this->proposals->submit($this->orga, $activity, $secret->refresh(), '11111', 'X'))->codes)->toBe(['secret' => 'secret_not_open']);
});

test('on ne supprime qu\'une activité CLOSE ; les propositions suivent', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);
    $this->proposals->submit($this->orga, $activity, $secret, '00000', 'Aldric');

    expect(refusal(fn () => $this->workflow->delete($this->orga, $activity))->codes)->toBe(['activity' => 'delete_requires_closed']);

    $this->workflow->close($this->orga, $activity);
    $this->workflow->delete($this->orga, $activity->refresh());

    expect(AnimDecodeActivity::count())->toBe(0)->and(AnimDecodeProposal::count())->toBe(0);
});

test('on rouvre une activité close ; refusé si une autre du même type est active', function () {
    $activity = decodeActivity($this);
    $this->workflow->generateSecret($this->orga, $activity);
    $this->workflow->close($this->orga, $activity);

    expect($activity->refresh()->status)->toBe('closed')->and($activity->closed_at)->not->toBeNull();

    // Une autre activité active du même personnage bloque la réouverture.
    decodeActivity($this, 'Deuxième');
    expect(refusal(fn () => $this->workflow->reopen($this->orga, $activity))->codes)->toBe(['activity' => 'reopen_blocked']);

    $this->workflow->close($this->orga, AnimDecodeActivity::where('name', 'Deuxième')->first());
    $reopened = $this->workflow->reopen($this->orga, $activity);
    expect($reopened->status)->toBe('launched')->and($reopened->closed_at)->toBeNull();
});

test('rouvrir une activité close avant tout coffre en scelle un', function () {
    $activity = decodeActivity($this);
    $this->workflow->close($this->orga, $activity);

    $this->workflow->reopen($this->orga, $activity);

    expect($this->workflow->pendingSecret($activity))->not->toBeNull()->and($activity->refresh()->launched_at)->not->toBeNull();
});

test('si l\'organisateur supprime son compte, l\'activité et les propositions restent (catégorie C)', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);
    $this->proposals->submit($this->orga, $activity, $secret, '00000', 'Aldric');

    $this->orga->delete();

    expect($activity->refresh()->character_id)->toBeNull()
        ->and(AnimDecodeProposal::count())->toBe(1);
});
