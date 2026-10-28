<?php

use App\Exceptions\MandateRefusal;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;
use App\Notifications\DecodeEmailConfirmation;
use App\Notifications\DecodeFinalReport;
use App\Notifications\DecodePasswordChanged;
use App\Notifications\DecodePasswordResetLink;
use App\Services\DecodeActor;
use App\Services\DecodeWorkflow;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\AssertionFailedError;

/*
| Décode le code — mode SANS COMPTE (tour 12 du fil). Slug public + mot de passe choisi, pseudo obligatoire, email facultatif
| confirmé par lien, une seule activité ouverte par connexion (empreinte d'IP), clôture définitive avec photo figée, réinitialisation
| par lien à usage unique. Aucun mot réservé : un slug comme « access » ou « open » n'est jamais masqué par une route.
*/

const ANON_PASSWORD = 'un-bon-mot-de-passe';
const PUBLIC_IP = '93.184.216.34';

beforeEach(function () {
    Notification::fake();
    $this->map = mandateMap();
});

afterEach(fn () => Carbon::setTestNow());

function anonPayload(array $over = []): array
{
    return $over + [
        'slug' => 'les-trois-coffres',
        'name' => 'Les Trois Coffres',
        'organizer_name' => 'Artifice',
        'password' => ANON_PASSWORD,
        'password_confirmation' => ANON_PASSWORD,
    ];
}

function createAnon($test, array $over = [], string $ip = PUBLIC_IP)
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/anim/decode-manage', anonPayload($over + (array_intersect_key($over, array_flip(['kingdom_id', 'province_id', 'city_id'])) ? [] : ['province_id' => $test->map['provinceA']->id])));
}

function anonCall($test, string $slug, string $path, array $body = [], string $password = ANON_PASSWORD)
{
    return $test->postJson("/api/v1/anim/decode-manage/{$slug}/{$path}", ['password' => $password] + $body);
}

/** La notification de classe donnée envoyée à l'adresse donnée (à la demande), ou null. */
function sentMail(string $class, string $email): ?object
{
    $found = null;
    try {
        Notification::assertSentOnDemand($class, function ($notification, $channels, $notifiable) use (&$found, $email) {
            if (($notifiable->routes['mail'] ?? null) === $email) {
                $found = $notification;

                return true;
            }

            return false;
        });
    } catch (AssertionFailedError) {
        return null;
    }

    return $found;
}

/** Le refus métier levé par un geste du workflow (ce fichier ne dépend d'aucun autre fichier de test). */
function anonRefusal(callable $call): MandateRefusal
{
    try {
        $call();
    } catch (MandateRefusal $e) {
        return $e;
    }
    throw new RuntimeException('Un refus était attendu.');
}

function renderedText(object $notification): string
{
    $mail = $notification->toMail(new AnonymousNotifiable);

    return $mail->subject."\n".implode("\n", $mail->introLines)."\n".$mail->actionText."\n".$mail->actionUrl;
}

/** Crée l'activité avec un email, le confirme, et rend l'activité. */
function anonWithVerifiedEmail($test, string $email = 'artifice@example.test', array $over = []): AnimDecodeActivity
{
    createAnon($test, ['email' => $email] + $over)->assertCreated();
    $activity = AnimDecodeActivity::where('slug', $over['slug'] ?? 'les-trois-coffres')->firstOrFail();
    $hash = sha1($email);
    $url = URL::temporarySignedRoute('anim.decode.email-confirm', now()->addDay(), ['slug' => $activity->slug, 'hash' => $hash]);
    $test->getJson($url)->assertOk();

    return $activity->refresh();
}

// ── Slug : aperçu et disponibilité (D14, D26, D27) ─────────────────────────────────────────────

test('l\'aperçu propose un slug sans chiffre, coupé à 20, et dit s\'il est pris', function () {
    $this->getJson('/api/v1/anim/decode-slug?name='.urlencode('Fête 2026 de « Décode le code » de l\'Office'))
        ->assertOk()
        ->assertJsonPath('proposal', 'fete-de-decode-le')
        ->assertJsonPath('valid', true)
        ->assertJsonPath('taken', false)
        ->assertJsonPath('max', 20);

    createAnon($this)->assertCreated();

    $this->getJson('/api/v1/anim/decode-slug?slug=les-trois-coffres')->assertOk()->assertJsonPath('valid', true)->assertJsonPath('taken', true);
    $this->getJson('/api/v1/anim/decode-slug?slug=abc1')->assertOk()->assertJsonPath('valid', false)->assertJsonPath('taken', false);
});

test('un nom fait de chiffres ne donne aucune proposition', function () {
    $this->getJson('/api/v1/anim/decode-slug?name=2026')->assertOk()->assertJsonPath('proposal', '')->assertJsonPath('valid', false);
});

// ── Création ──────────────────────────────────────────────────────────────────────────────────

test('créer une activité sans compte : 201, mode anonymous, aucune donnée secrète dans la réponse', function () {
    $response = createAnon($this, ['kingdom_id' => $this->map['provinceA']->kingdom_id]);

    $response->assertCreated()
        ->assertJsonPath('activity.slug', 'les-trois-coffres')
        ->assertJsonPath('activity.mode', 'anonymous')
        ->assertJsonPath('activity.organizer', 'Artifice')
        ->assertJsonPath('activity.place.kind', 'kingdom')
        ->assertJsonPath('activity.status', 'new')
        ->assertJsonPath('email_pending', false);
    expect($response->getContent())->not->toContain('password_hash')->not->toContain('creator_ip_hash')->not->toContain(ANON_PASSWORD);

    $activity = AnimDecodeActivity::sole();
    expect($activity->is_anonymous)->toBeTrue()
        ->and($activity->character_id)->toBeNull()
        ->and($activity->password_hash)->not->toBe(ANON_PASSWORD)
        ->and(Hash::check(ANON_PASSWORD, $activity->password_hash))->toBeTrue();
});

test('le pseudo est OBLIGATOIRE (D12), le mot de passe aussi, avec ressaisie (D28)', function () {
    createAnon($this, ['organizer_name' => ''])->assertStatus(422)->assertJsonPath('codes.organizer_name', 'validation.required');
    createAnon($this, ['password' => ''])->assertStatus(422)->assertJsonPath('codes.password', 'validation.required');
    createAnon($this, ['password_confirmation' => 'autre-chose-123'])->assertStatus(422)->assertJsonPath('codes.password', 'validation.confirmed');
    expect(AnimDecodeActivity::count())->toBe(0);
});

test('mot de passe : 10 caractères au moins, ni le slug ni le pseudo — imposé par le SERVEUR', function () {
    createAnon($this, ['password' => 'court', 'password_confirmation' => 'court'])
        ->assertStatus(422)->assertJsonPath('codes.password', 'password_too_short')
        ->assertJsonStructure(['messages' => ['password' => ['fr', 'en']]]);

    createAnon($this, ['organizer_name' => 'Artifice-Roi', 'password' => 'Artifice-Roi', 'password_confirmation' => 'Artifice-Roi'])
        ->assertStatus(422)->assertJsonPath('codes.password', 'password_too_guessable');
    createAnon($this, ['slug' => 'les-trois-coffres', 'password' => 'les-trois-coffres', 'password_confirmation' => 'les-trois-coffres'])
        ->assertStatus(422)->assertJsonPath('codes.password', 'password_too_guessable');
});

test('slug invalide ou déjà pris : refus bilingue, jamais de suffixe', function () {
    createAnon($this, ['slug' => 'abc1'])->assertStatus(422)->assertJsonPath('codes.slug', 'slug_invalid')->assertJsonStructure(['messages' => ['slug' => ['fr', 'en']]]);

    createAnon($this)->assertCreated();
    createAnon($this, [], '93.184.216.35')->assertStatus(422)->assertJsonPath('codes.slug', 'slug_taken');
    expect(AnimDecodeActivity::pluck('slug')->all())->toBe(['les-trois-coffres']);
});

test('un seul niveau de lieu', function () {
    createAnon($this, ['kingdom_id' => $this->map['provinceA']->kingdom_id, 'city_id' => $this->map['cityA']->id])
        ->assertStatus(422)->assertJsonPath('codes.place', 'place_exclusive');
});

test('la création est limitée en fréquence (3 par minute et par adresse), même pour des essais refusés', function () {
    foreach (range(1, 3) as $i) {
        createAnon($this, ['slug' => 'abc1'], '93.184.216.40')->assertStatus(422);
    }
    // 4e essai de la même adresse dans la minute : 429 au contrat d'erreur.
    createAnon($this, ['slug' => 'abc1'], '93.184.216.40')->assertStatus(429)->assertJsonStructure(['code', 'messages' => ['fr', 'en']]);
    // Une autre adresse n'est pas touchée.
    createAnon($this, [], '93.184.216.41')->assertCreated();
});

// ── IP : une seule activité ouverte par connexion (D15, D16, D17) ──────────────────────────────────

test('une seule activité sans compte non close par connexion ; le refus dit pourquoi', function () {
    createAnon($this, ['slug' => 'premiere'], PUBLIC_IP)->assertCreated();

    createAnon($this, ['slug' => 'seconde'], PUBLIC_IP)
        ->assertStatus(422)
        ->assertJsonPath('codes.activity', 'ip_limit')
        ->assertJsonStructure(['messages' => ['activity' => ['fr', 'en']]]);

    // Une autre connexion n'est pas concernée.
    createAnon($this, ['slug' => 'troisieme'], '93.184.216.99')->assertCreated();
});

test('une fois la première close, la même connexion peut en créer une autre', function () {
    createAnon($this, ['slug' => 'premiere'], PUBLIC_IP)->assertCreated();
    anonCall($this, 'premiere', 'close')->assertOk();

    createAnon($this, ['slug' => 'seconde'], PUBLIC_IP)->assertCreated();
});

test('l\'IP n\'est jamais enregistrée : seulement une empreinte HMAC, effacée à la clôture (D15, D16)', function () {
    createAnon($this, ['slug' => 'premiere'], PUBLIC_IP)->assertCreated();

    $stored = AnimDecodeActivity::sole()->creator_ip_hash;
    expect($stored)->toMatch('/^[a-f0-9]{64}$/')->and($stored)->not->toBe(PUBLIC_IP);
    expect(json_encode(DB::table('anim_decode_activities')->get()))->not->toContain(PUBLIC_IP);

    anonCall($this, 'premiere', 'close')->assertOk();

    expect(AnimDecodeActivity::sole()->creator_ip_hash)->toBeNull();
});

test('une adresse privée ou de bouclage n\'est pas fingerprintée : la limite ne bloque pas tout le monde', function () {
    createAnon($this, ['slug' => 'premiere'], '127.0.0.1')->assertCreated();
    createAnon($this, ['slug' => 'seconde'], '10.0.0.5')->assertCreated();

    expect(AnimDecodeActivity::whereNotNull('creator_ip_hash')->count())->toBe(0);
});

// ── Email facultatif, confirmé par lien (D23, D29) ──────────────────────────────────────────────────

test('sans email : aucun message envoyé, rien à confirmer', function () {
    createAnon($this)->assertCreated()->assertJsonPath('activity.email', ['set' => false, 'verified' => false]);

    Notification::assertNothingSent();
});

test('avec un email : un lien de confirmation part à l\'adresse, jamais un mot de passe', function () {
    createAnon($this, ['email' => 'Artifice@Example.test'])->assertCreated()
        ->assertJsonPath('email_pending', true)
        ->assertJsonPath('activity.email', ['set' => true, 'verified' => false]);

    $mail = sentMail(DecodeEmailConfirmation::class, 'artifice@example.test');
    expect($mail)->not->toBeNull();

    $text = renderedText($mail);
    expect($text)->toContain('Confirmer mon adresse / Confirm my address')
        ->toContain('Bonjour') // français d'abord
        ->toContain('Hello')   // puis anglais
        ->not->toContain(ANON_PASSWORD)
        ->and($mail->toMail(new AnonymousNotifiable)->actionUrl)->toContain('/app/anim/decode/confirmer-email?')->toContain('signature=');
});

test('l\'adresse est chiffrée en base', function () {
    createAnon($this, ['email' => 'artifice@example.test'])->assertCreated();

    expect(DB::table('anim_decode_activities')->value('organizer_email'))->not->toContain('artifice')
        ->and(AnimDecodeActivity::sole()->organizer_email)->toBe('artifice@example.test');
});

test('le lien signé confirme l\'adresse ; une empreinte fausse ou un lien non signé est refusé', function () {
    createAnon($this, ['email' => 'artifice@example.test'])->assertCreated();

    $good = URL::temporarySignedRoute('anim.decode.email-confirm', now()->addDay(), ['slug' => 'les-trois-coffres', 'hash' => sha1('artifice@example.test')]);
    $wrong = URL::temporarySignedRoute('anim.decode.email-confirm', now()->addDay(), ['slug' => 'les-trois-coffres', 'hash' => sha1('autre@example.test')]);

    $this->getJson($wrong)->assertStatus(422)->assertJsonPath('codes.email', 'email_confirm_invalid');
    $this->getJson('/api/v1/anim/decode-email-confirm/les-trois-coffres/'.sha1('artifice@example.test'))->assertForbidden();
    expect(AnimDecodeActivity::sole()->hasVerifiedEmail())->toBeFalse();

    $this->getJson($good)->assertOk();
    expect(AnimDecodeActivity::sole()->hasVerifiedEmail())->toBeTrue();
});

// ── Preuve d'auteur (slug public + mot de passe) ────────────────────────────────────────────────────

test('open : le bon mot de passe ouvre la gestion', function () {
    createAnon($this)->assertCreated();

    anonCall($this, 'les-trois-coffres', 'open')->assertOk()->assertJsonPath('activity.slug', 'les-trois-coffres')->assertJsonPath('activity.can_reopen', false);
});

test('le refus est IDENTIQUE pour mot de passe faux, slug inconnu et activité privée', function () {
    createAnon($this)->assertCreated();
    $private = mandatePlayer($this->map['cityA']);
    app(DecodeWorkflow::class)->create($private, AnimActivityType::where('key', 'decode')->first(), ['name' => 'Privée', 'slug' => 'privee']);

    $bodies = [
        anonCall($this, 'les-trois-coffres', 'open', [], 'mauvais-mot-de-passe')->assertForbidden()->json(),
        anonCall($this, 'inconnue', 'open')->assertForbidden()->json(),
        anonCall($this, 'privee', 'open')->assertForbidden()->json(),
    ];

    expect($bodies[0])->toBe($bodies[1])->toBe($bodies[2])->and($bodies[0]['code'] ?? $bodies[0]['codes']['password'])->toBe('access_denied');
});

test('les essais de mot de passe sont limités par slug (5 par minute) — compté que le slug existe ou non', function () {
    createAnon($this)->assertCreated();

    foreach (range(1, 5) as $i) {
        anonCall($this, 'les-trois-coffres', 'open', [], 'faux-mot-de-passe-'.$i)->assertForbidden();
    }
    anonCall($this, 'les-trois-coffres', 'open', [], 'faux-mot-de-passe-6')->assertStatus(429);
    // Même le bon mot de passe est retenu un moment : c'est le prix de la protection contre l'essai en rafale.
    anonCall($this, 'les-trois-coffres', 'open')->assertStatus(429);
});

// ── Gestion : coffres, propositions (D34 : noms de personnages) ──────────────────────────────────────

test('sceller, proposer, percer : le code n\'est visible de PERSONNE tant que le coffre n\'est pas percé', function () {
    createAnon($this)->assertCreated();

    $first = anonCall($this, 'les-trois-coffres', 'secrets')->assertCreated()->json('activity.current');
    $code = $foundCode = decodeSecretCode($first['id']);
    expect($code)->toMatch('/^\d{5}$/')->and($first)->not->toHaveKey('code');

    $public = $this->getJson('/api/v1/anim/decode-activities/les-trois-coffres')->assertOk();
    $public->assertJsonPath('activity.current.number', 1)->assertJsonMissingPath('activity.current.code');
    expect($public->getContent())->not->toContain($code)->not->toContain('password_hash');

    anonCall($this, 'les-trois-coffres', "secrets/{$first['id']}/proposals", ['combination' => '99999', 'character_name' => 'Aldric'])
        ->assertCreated()->assertJsonPath('found', false)->assertJsonPath('proposal.character_name', 'Aldric');

    $hit = anonCall($this, 'les-trois-coffres', "secrets/{$first['id']}/proposals", ['combination' => decodeSecretCode($first['id']), 'character_name' => 'Brunehaut'])
        ->assertCreated()->assertJsonPath('found', true)->assertJsonPath('proposal.points', 20)->assertJsonPath('activity.status', 'relaunch');

    $this->getJson('/api/v1/anim/decode-activities/les-trois-coffres')->assertOk()
        ->assertJsonPath('activity.opened.0.code', $foundCode)
        ->assertJsonPath('activity.opened.0.discoverer', 'Brunehaut')
        ->assertJsonPath('activity.ranking.0.character_name', 'Brunehaut');
    expect($hit->json('activity.current.number'))->toBe(2);
});

test('sans le mot de passe, aucun geste de gestion ne passe', function () {
    createAnon($this)->assertCreated();
    anonCall($this, 'les-trois-coffres', 'secrets')->assertCreated();
    $secretId = AnimDecodeSecret::sole()->id;

    foreach ([
        ['secrets', []],
        ["secrets/{$secretId}/proposals", ['combination' => '12345', 'character_name' => 'X']],
        ['close', []],
        ['export/rules', []],
    ] as [$path, $body]) {
        // 4 essais faux + le sceau de départ = 5 : juste sous la limite des 5 essais par minute et par slug.
        anonCall($this, 'les-trois-coffres', $path, $body, 'pas-le-bon-mot-de-passe')->assertForbidden();
    }

    expect(AnimDecodeActivity::sole()->status)->toBe('launched');
});

test('les exports BBCode donnent un lien par SLUG vers le site des joueurs', function () {
    config(['app.frontend_url' => 'https://site.example']);
    createAnon($this)->assertCreated();
    anonCall($this, 'les-trois-coffres', 'secrets')->assertCreated();

    $rules = anonCall($this, 'les-trois-coffres', 'export/rules')->assertOk()->json('bbcode');
    $results = anonCall($this, 'les-trois-coffres', 'export/results')->assertOk()->json('bbcode');

    expect($rules)->toContain('[url=https://site.example/public/activite/decode/les-trois-coffres]')->toContain('Organisateur : Artifice');
    expect($results)->toContain('[url=https://site.example/public/activite/decode/les-trois-coffres]');
});

// ── Clôture DÉFINITIVE : photo figée, effacements, compte rendu (D16, D18, D19, D30, D31) ─────────────────

test('la clôture fige le résumé et efface tout le reste', function () {
    createAnon($this, ['province_id' => $this->map['provinceA']->id], PUBLIC_IP)->assertCreated();
    $first = anonCall($this, 'les-trois-coffres', 'secrets')->assertCreated()->json('activity.current');
    $foundCode = decodeSecretCode($first['id']);
    anonCall($this, 'les-trois-coffres', "secrets/{$first['id']}/proposals", ['combination' => '00000', 'character_name' => 'Aldric'])->assertCreated();
    anonCall($this, 'les-trois-coffres', "secrets/{$first['id']}/proposals", ['combination' => decodeSecretCode($first['id']), 'character_name' => 'Brunehaut'])->assertCreated();

    anonCall($this, 'les-trois-coffres', 'close')->assertOk()->assertJsonPath('activity.status', 'closed');

    $activity = AnimDecodeActivity::sole();
    expect($activity->status)->toBe('closed')
        ->and($activity->closed_at)->not->toBeNull()
        ->and($activity->password_hash)->toBeNull()
        ->and($activity->creator_ip_hash)->toBeNull()
        ->and($activity->organizer_email)->toBeNull()
        ->and($activity->organizer_email_verified_at)->toBeNull()
        ->and($activity->reset_token_hash)->toBeNull()
        ->and(AnimDecodeSecret::count())->toBe(0)
        ->and(AnimDecodeProposal::count())->toBe(0);

    // La page publique se lit sur la photo : nom, organisateur, lieu, résultats, classement — pas les propositions.
    $page = $this->getJson('/api/v1/anim/decode-activities/les-trois-coffres')->assertOk();
    $page->assertJsonPath('activity.name', 'Les Trois Coffres')
        ->assertJsonPath('activity.organizer', 'Artifice')
        ->assertJsonPath('activity.place.name', $this->map['provinceA']->province_name)
        ->assertJsonPath('activity.opened.0.code', $foundCode)
        ->assertJsonPath('activity.opened.0.discoverer', 'Brunehaut')
        ->assertJsonPath('activity.ranking.0.character_name', 'Brunehaut')
        ->assertJsonPath('activity.ranking.0.points', 20)
        ->assertJsonPath('activity.current', null)
        ->assertJsonPath('activity.status', 'closed');
    // Ni les propositions (leurs combinaisons), ni le coffre suivant (non percé, jamais révélé) ne sont gardés.
    expect($page->getContent())->not->toContain('combination')->not->toContain('"proposals"');
});

test('une activité sans compte close ne se rouvre pas et ne se gère plus (D19)', function () {
    createAnon($this)->assertCreated();
    anonCall($this, 'les-trois-coffres', 'close')->assertOk();

    anonCall($this, 'les-trois-coffres', 'open')->assertForbidden();
    anonCall($this, 'les-trois-coffres', 'secrets')->assertForbidden();

    $activity = AnimDecodeActivity::sole();
    expect(anonRefusal(fn () => app(DecodeWorkflow::class)->reopen(DecodeActor::forAnonymous($activity), $activity))->codes)->toBe(['activity' => 'reopen_anonymous']);
});

test('le slug reste pris après la clôture, et l\'auteur ne peut pas supprimer son résumé', function () {
    createAnon($this)->assertCreated();
    anonCall($this, 'les-trois-coffres', 'close')->assertOk();

    createAnon($this, [], '93.184.216.77')->assertStatus(422)->assertJsonPath('codes.slug', 'slug_taken');

    $activity = AnimDecodeActivity::sole();
    expect(anonRefusal(fn () => app(DecodeWorkflow::class)->delete(DecodeActor::forAnonymous($activity), $activity))->codes)->toBe(['activity' => 'delete_anonymous']);
});

test('avec un email confirmé : le compte rendu COMPLET part à la clôture (propositions comprises), puis l\'adresse est effacée', function () {
    $activity = anonWithVerifiedEmail($this);
    $first = anonCall($this, 'les-trois-coffres', 'secrets')->assertCreated()->json('activity.current');
    anonCall($this, 'les-trois-coffres', "secrets/{$first['id']}/proposals", ['combination' => '00000', 'character_name' => 'Aldric'])->assertCreated();
    $unfoundCode = decodeSecretCode($first['id']);

    anonCall($this, 'les-trois-coffres', 'close')->assertOk();

    $mail = sentMail(DecodeFinalReport::class, 'artifice@example.test');
    expect($mail)->not->toBeNull();
    $text = renderedText($mail);
    expect($text)->toContain('Aldric')       // les propositions : seule copie (D31)
        ->toContain('00000')
        ->toContain($unfoundCode)             // le coffre non percé : révélé à la clôture seulement, dans le compte rendu
        ->toContain('Compte rendu')           // français
        ->toContain('Report')                 // puis anglais
        ->not->toContain(ANON_PASSWORD);

    expect($activity->refresh()->organizer_email)->toBeNull()->and(AnimDecodeProposal::count())->toBe(0);
});

test('une adresse NON confirmée ne reçoit rien', function () {
    createAnon($this, ['email' => 'artifice@example.test'])->assertCreated();
    anonCall($this, 'les-trois-coffres', 'close')->assertOk();

    expect(sentMail(DecodeFinalReport::class, 'artifice@example.test'))->toBeNull();
});

test('l\'échec de l\'envoi du compte rendu ne bloque jamais la clôture', function () {
    $activity = anonWithVerifiedEmail($this);
    // À partir d'ici, tout envoi échoue (panne du courrier).
    Notification::shouldReceive('route')->andThrow(new RuntimeException('smtp down'));

    anonCall($this, 'les-trois-coffres', 'close')->assertOk()->assertJsonPath('activity.status', 'closed');

    expect($activity->refresh()->isClosed())->toBeTrue()->and($activity->organizer_email)->toBeNull();
});

// ── Réinitialisation du mot de passe (D5, D11, D35, D37) ────────────────────────────────────────────

function resetTokenFrom(object $mailNotification): string
{
    $url = $mailNotification->toMail(new AnonymousNotifiable)->actionUrl;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['token'];
}

test('l\'auteur avec email confirmé demande lui-même un lien à usage unique (D35) — jamais de mot de passe par email', function () {
    $activity = anonWithVerifiedEmail($this);

    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(202);

    $mail = sentMail(DecodePasswordResetLink::class, 'artifice@example.test');
    expect($mail)->not->toBeNull();
    $token = resetTokenFrom($mail);
    $text = renderedText($mail);

    expect($text)->toContain('/app/anim/decode/reinitialiser?')->toContain('24')->not->toContain(ANON_PASSWORD);
    // Le jeton est stocké HACHÉ, jamais en clair.
    expect($activity->refresh()->reset_token_hash)->toBe(hash('sha256', $token))->and($activity->reset_mode)->toBe('email');
});

test('la demande répond pareil pour une activité inconnue, sans email ou avec une adresse non confirmée — et n\'envoie rien', function () {
    createAnon($this, ['slug' => 'sans-email'])->assertCreated();
    createAnon($this, ['slug' => 'non-confirmee', 'email' => 'x@example.test'], '93.184.216.50')->assertCreated();

    $answers = [];
    foreach (['inconnue-du-tout', 'sans-email', 'non-confirmee'] as $slug) {
        $answers[] = $this->postJson("/api/v1/anim/decode-manage/{$slug}/reset-request")->assertStatus(202)->json();
    }

    expect($answers[0])->toBe($answers[1])->toBe($answers[2]);
    expect(sentMail(DecodePasswordResetLink::class, 'x@example.test'))->toBeNull();
});

test('le lien permet de CHOISIR un nouveau mot de passe, une seule fois, et l\'auteur en est averti (D37)', function () {
    anonWithVerifiedEmail($this);
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(202);
    $token = resetTokenFrom(sentMail(DecodePasswordResetLink::class, 'artifice@example.test'));

    $new = 'un-tout-nouveau-secret';
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-complete', ['token' => $token, 'password' => $new, 'password_confirmation' => $new])->assertOk();

    // L'ancien mot de passe ne marche plus, le nouveau oui.
    anonCall($this, 'les-trois-coffres', 'open')->assertForbidden();
    anonCall($this, 'les-trois-coffres', 'open', [], $new)->assertOk();

    // À usage unique.
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-complete', ['token' => $token, 'password' => 'encore-un-autre-1', 'password_confirmation' => 'encore-un-autre-1'])
        ->assertStatus(422)->assertJsonPath('codes.token', 'reset_invalid');

    // Chaque changement est signalé (D37) : message sans mot de passe, bilingue.
    $changed = sentMail(DecodePasswordChanged::class, 'artifice@example.test');
    expect($changed)->not->toBeNull();
    expect(renderedText($changed))->toContain('modifié')->toContain('changed')->not->toContain($new);

    $activity = AnimDecodeActivity::sole();
    expect($activity->password_reset_at)->not->toBeNull()->and($activity->reset_token_hash)->toBeNull();
});

test('un lien périmé, faux ou annulé par un plus récent est refusé — même message', function () {
    anonWithVerifiedEmail($this);
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(202);
    $old = resetTokenFrom(sentMail(DecodePasswordResetLink::class, 'artifice@example.test'));

    $pw = 'un-tout-nouveau-secret';
    $try = fn (string $token) => $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-complete', ['token' => $token, 'password' => $pw, 'password_confirmation' => $pw]);

    $try('faux-jeton')->assertStatus(422)->assertJsonPath('codes.token', 'reset_invalid');

    // Un nouveau lien annule le précédent.
    Notification::fake();
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(202);
    $fresh = resetTokenFrom(sentMail(DecodePasswordResetLink::class, 'artifice@example.test'));
    $try($old)->assertStatus(422);

    // Périmé après 24 h.
    Carbon::setTestNow(now()->addHours(25));
    $try($fresh)->assertStatus(422)->assertJsonPath('codes.token', 'reset_invalid');
});

test('le nouveau mot de passe suit les mêmes règles (10 caractères, pas le slug)', function () {
    anonWithVerifiedEmail($this);
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(202);
    $token = resetTokenFrom(sentMail(DecodePasswordResetLink::class, 'artifice@example.test'));

    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-complete', ['token' => $token, 'password' => 'court', 'password_confirmation' => 'court'])
        ->assertStatus(422)->assertJsonPath('codes.password', 'password_too_short');

    // Le jeton n'est pas consommé par un refus de forme du mot de passe.
    $ok = 'un-tout-nouveau-secret';
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-complete', ['token' => $token, 'password' => $ok, 'password_confirmation' => $ok])->assertOk();
});

test('la demande de lien est limitée (3 par minute et par slug)', function () {
    createAnon($this)->assertCreated();

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(202);
    }
    $this->postJson('/api/v1/anim/decode-manage/les-trois-coffres/reset-request')->assertStatus(429);
});

// ── Aucun mot réservé (D14) ─────────────────────────────────────────────────────────────────────────────

test('un slug comme « open » ou « access » n\'est jamais masqué par une route', function () {
    foreach (['open', 'access', 'close', 'secrets'] as $i => $slug) {
        createAnon($this, ['slug' => $slug, 'name' => ucfirst($slug).' des coffres'], '93.184.217.'.(10 + $i))->assertCreated();
        $this->getJson("/api/v1/anim/decode-activities/{$slug}")->assertOk()->assertJsonPath('activity.slug', $slug);
        anonCall($this, $slug, 'open')->assertOk();
    }
});

test('un slug mal formé dans l\'adresse ne tombe sur aucune activité', function () {
    createAnon($this)->assertCreated();

    $this->getJson('/api/v1/anim/decode-activities/Les-Trois-Coffres')->assertNotFound();
    $this->getJson('/api/v1/anim/decode-activities/inconnue')->assertNotFound()->assertJsonPath('code', 'activity_not_found');
});

test('le mode sans compte n\'a aucun lien avec un compte (hors UserDataLifecycleTest)', function () {
    createAnon($this)->assertCreated();

    $columns = Schema::getColumnListing('anim_decode_proposals');
    expect($columns)->not->toContain('user_id')->not->toContain('character_id')->toContain('character_name');
    expect(AnimDecodeActivity::sole()->character_id)->toBeNull();
});

test('le lieu est obligatoire : sans royaume, province ni ville, la création est refusée', function () {
    $payload = anonPayload();

    $this->withServerVariables(['REMOTE_ADDR' => PUBLIC_IP])->postJson('/api/v1/anim/decode-manage', $payload)
        ->assertStatus(422)->assertJsonPath('codes.place', 'place_required')
        ->assertJsonStructure(['messages' => ['place' => ['fr', 'en']]]);
});
