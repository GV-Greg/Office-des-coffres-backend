<?php

use App\Exceptions\MandateRefusal;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;
use App\Support\DecodeSlug;
use Illuminate\Support\Carbon;

/*
| Décode le code — gestes de l'organisateur PRIVÉ (brief admin/modules/animation/brief-coffres-scelles.md ; tour 12 du
| fil : slug pour tous, un seul niveau de lieu, suppression avec le personnage). Autorisation par PROPRIÉTÉ.
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
    return $test->workflow->create($test->orga, $test->type, ['name' => $name, 'slug' => DecodeSlug::propose($name)] + $extra);
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

test('une activité se crée neuve, rattachée à son organisateur, avec son slug, sans coffre', function () {
    $activity = decodeActivity($this, extra: ['province_id' => $this->map['provinceA']->id]);

    expect($activity->status)->toBe('new')
        ->and($activity->slug)->toBe('coffre-des-marches')
        ->and($activity->is_anonymous)->toBeFalse()
        ->and($activity->character_id)->toBe($this->orga->id)
        ->and($activity->launched_at)->toBeNull()
        ->and($activity->secrets()->count())->toBe(0);
});

test('un seul niveau de lieu : royaume, province OU ville', function () {
    $kingdom = $this->map['provinceA']->kingdom_id;
    $combos = [
        ['kingdom_id' => $kingdom, 'province_id' => $this->map['provinceA']->id],
        ['province_id' => $this->map['provinceA']->id, 'city_id' => $this->map['cityA']->id],
        ['kingdom_id' => $kingdom, 'city_id' => $this->map['cityA']->id],
    ];

    foreach ($combos as $extra) {
        expect(refusal(fn () => decodeActivity($this, extra: $extra))->codes)->toBe(['place' => 'place_exclusive']);
    }
});

test('le royaume est un niveau de lieu à part entière', function () {
    $activity = decodeActivity($this, extra: ['kingdom_id' => $this->map['provinceA']->kingdom_id]);

    expect($activity->kingdom_id)->toBe($this->map['provinceA']->kingdom_id)->and($activity->province_id)->toBeNull();
});

test('le slug doit être valable et libre (D14, D27) — jamais de suffixe automatique', function () {
    foreach (['abc1', 'ab', 'Abc', 'a--b', str_repeat('a', 21)] as $bad) {
        expect(refusal(fn () => $this->workflow->create($this->orga, $this->type, ['name' => 'Coffre', 'slug' => $bad]))->codes)->toBe(['slug' => 'slug_invalid']);
    }

    decodeActivity($this);
    // Même slug demandé par un autre personnage : refusé tel quel, jamais « -2 ».
    $e = refusal(fn () => $this->workflow->create($this->other, $this->type, ['name' => 'Coffre des Marches', 'slug' => 'coffre-des-marches']));
    expect($e->codes)->toBe(['slug' => 'slug_taken'])->and(AnimDecodeActivity::count())->toBe(1);
});

test('un type désactivé ne reçoit plus d\'activité', function () {
    $this->type->update(['is_active' => false]);

    expect(refusal(fn () => decodeActivity($this))->codes)->toBe(['type' => 'type_inactive']);
});

test('une seule activité non close du même type par personnage, mais pas de limite pour le total', function () {
    decodeActivity($this);

    expect(refusal(fn () => decodeActivity($this, 'Second'))->codes)->toBe(['name' => 'activity_already_active']);

    // Un autre personnage a les siennes ; une fois la première close, le premier peut en créer une autre.
    $this->workflow->create($this->other, $this->type, ['name' => 'Celui de l\'autre', 'slug' => 'celui-de-lautre']);
    $this->workflow->close($this->orga, AnimDecodeActivity::where('slug', 'coffre-des-marches')->first());
    expect(decodeActivity($this, 'Second')->status)->toBe('new');
});

test('le premier coffre passe l\'activité en cours et pose launched_at une fois', function () {
    atGameTime('2026-10-01 10:00');
    $activity = decodeActivity($this);

    $secret = $this->workflow->generateSecret($this->orga, $activity);

    expect($secret->code)->toMatch('/^\d{5}$/')
        ->and($secret->status)->toBeFalse()
        ->and($activity->refresh()->status)->toBe('launched')
        ->and($activity->launched_at)->not->toBeNull();
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
        ->and($hit['proposal']->character_name)->toBe('Brunehaut')
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

test('on ne supprime qu\'une activité PRIVÉE close ; les propositions suivent', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);
    $this->proposals->submit($this->orga, $activity, $secret, '00000', 'Aldric');

    expect(refusal(fn () => $this->workflow->delete($this->orga, $activity))->codes)->toBe(['activity' => 'delete_requires_closed']);

    $this->workflow->close($this->orga, $activity);
    $this->workflow->delete($this->orga, $activity->refresh());

    expect(AnimDecodeActivity::count())->toBe(0)->and(AnimDecodeProposal::count())->toBe(0);
});

test('la clôture d\'une activité privée garde TOUT : coffre, propositions, rien n\'est figé ni effacé (D20)', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);
    $this->proposals->submit($this->orga, $activity, $secret, '00000', 'Aldric');

    $closed = $this->workflow->close($this->orga, $activity);

    expect($closed->status)->toBe('closed')
        ->and($closed->closing_snapshot)->toBeNull()
        ->and($activity->secrets()->count())->toBe(1)
        ->and(AnimDecodeProposal::count())->toBe(1);
});

test('on rouvre une activité privée close ; refusé si une autre du même type est active', function () {
    $activity = decodeActivity($this);
    $this->workflow->generateSecret($this->orga, $activity);
    $this->workflow->close($this->orga, $activity);

    expect($activity->refresh()->status)->toBe('closed')->and($activity->closed_at)->not->toBeNull();

    // Une autre activité active du même personnage bloque la réouverture.
    decodeActivity($this, 'Deuxième');
    expect(refusal(fn () => $this->workflow->reopen($this->orga, $activity))->codes)->toBe(['activity' => 'reopen_blocked']);

    $this->workflow->close($this->orga, AnimDecodeActivity::where('slug', 'deuxieme')->first());
    $reopened = $this->workflow->reopen($this->orga, $activity);
    expect($reopened->status)->toBe('launched')->and($reopened->closed_at)->toBeNull();
});

test('rouvrir une activité close avant tout coffre en scelle un', function () {
    $activity = decodeActivity($this);
    $this->workflow->close($this->orga, $activity);

    $this->workflow->reopen($this->orga, $activity);

    expect($this->workflow->pendingSecret($activity))->not->toBeNull()->and($activity->refresh()->launched_at)->not->toBeNull();
});

/*
| D21 (Greg, 28/10/2026) : une activité PRIVÉE disparaît avec le personnage ou le compte de son organisateur. Le lot 1 la
| laissait survivre (catégorie C) ; la décision est inverse.
*/
test('supprimer le PERSONNAGE emporte l\'activité privée, ses coffres et ses propositions', function () {
    $activity = decodeActivity($this);
    $secret = $this->workflow->generateSecret($this->orga, $activity);
    $this->proposals->submit($this->orga, $activity, $secret, '00000', 'Aldric');

    $this->orga->delete();

    expect(AnimDecodeActivity::count())->toBe(0)->and(AnimDecodeSecret::count())->toBe(0)->and(AnimDecodeProposal::count())->toBe(0);
});

test('supprimer le COMPTE emporte aussi les activités de tous ses personnages', function () {
    decodeActivity($this);
    $second = mandatePlayer($this->map['cityA'], ['user_id' => $this->orga->user_id]);
    $this->workflow->create($second, $this->type, ['name' => 'Autre', 'slug' => 'autre-activite']);

    $this->orga->user->delete();

    expect(AnimDecodeActivity::count())->toBe(0);
});

test('l\'activité d\'un AUTRE personnage reste quand on en supprime un', function () {
    decodeActivity($this);
    $this->workflow->create($this->other, $this->type, ['name' => 'Autre', 'slug' => 'autre-activite']);

    $this->orga->delete();

    expect(AnimDecodeActivity::pluck('slug')->all())->toBe(['autre-activite']);
});
