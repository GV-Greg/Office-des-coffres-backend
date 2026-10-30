<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeSecret;
use App\Models\Character;
use App\Models\User;
use App\Support\DecodePassword;
use App\Support\DecodeSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Décode le code — gestes de l'organisateur (brief admin/modules/animation/brief-coffres-scelles.md ; fil
 * admin/echanges/animation-coffres-scelles, synthèse du tour 12). SEUL écrivain des activités et des secrets ; les
 * contrôleurs ne valident que la forme.
 *
 * 🔴 Autorisation PAR PROPRIÉTÉ, jamais par poste (premier module du projet sans Spatie ni mandat) : un geste de gestion
 * reçoit un `DecodeActor` — un personnage (mode privé) ou un auteur sans compte qui a prouvé son mot de passe — et vérifie
 * la propriété EN PREMIER. Un refus de propriété est un 403 ; les autres refus restent des 422 au contrat d'AnimApiErrors.
 *
 * Règles de Greg : une activité privée ne se supprime que close (D20) ; une réouverture est refusée si une autre
 * activité du même type est active pour le personnage ; une activité SANS COMPTE ne se rouvre jamais (D19) et son auteur
 * ne la supprime pas (l'administrateur du site le peut, D13) ; une seule activité sans compte non close par connexion (D17).
 */
class DecodeWorkflow
{
    public function __construct(
        private readonly DecodeClosing $closing,
        private readonly DecodeMailer $mailer,
    ) {}

    /**
     * Le personnage du compte qui possède une activité PRIVÉE. Les routes `decode-activities/{activity}` ne portent pas de
     * personnage : l'acteur est l'organisateur de l'activité, à condition qu'il appartienne au compte authentifié.
     *
     * @throws MandateRefusal 403
     */
    public function ownerOf(User $user, AnimDecodeActivity $activity): Character
    {
        $owner = (! $activity->is_anonymous && $activity->character_id)
            ? $user->characters()->whereKey($activity->character_id)->first()
            : null;

        return $owner ?? throw self::forbidden();
    }

    /**
     * Création PRIVÉE (liée au personnage).
     *
     * @param  array{slug: string, name: string, kingdom_id?: ?int, province_id?: ?int, city_id?: ?int}  $data
     *
     * @throws MandateRefusal
     */
    public function create(Character $actor, AnimActivityType $type, array $data): AnimDecodeActivity
    {
        $this->assertCreatable($type, $data);
        if ($this->hasActiveOfType($actor, $type)) {
            throw MandateRefusal::make('name', 'anim.api.activity_already_active');
        }

        return $this->persist($this->attributes($type, $data) + [
            'character_id' => $actor->id,
            'is_anonymous' => false,
        ]);
    }

    /**
     * Création SANS COMPTE : slug, pseudo OBLIGATOIRE (D12), mot de passe choisi (D10), email FACULTATIF (D23) confirmé par un
     * lien. `$ipHash` est l'empreinte de la connexion (DecodeIp), nulle si l'adresse n'est pas fiable.
     *
     * @param  array{slug: string, name: string, organizer_name: ?string, password: string, email?: ?string, kingdom_id?: ?int, province_id?: ?int, city_id?: ?int}  $data
     *
     * @throws MandateRefusal
     */
    public function createAnonymous(AnimActivityType $type, array $data, ?string $ipHash): AnimDecodeActivity
    {
        $this->assertCreatable($type, $data);

        $pseudo = trim((string) ($data['organizer_name'] ?? ''));
        if ($pseudo === '') {
            throw MandateRefusal::make('organizer_name', 'anim.api.organizer_required');
        }
        DecodePassword::assertValid((string) ($data['password'] ?? ''), $data['slug'], $pseudo);

        // D17 : UNE SEULE activité sans compte non close par connexion (empreinte d'IP). Le refus dit pourquoi.
        if ($ipHash !== null && AnimDecodeActivity::query()
            ->where('is_anonymous', true)
            ->where('status', '!=', AnimDecodeActivity::CLOSED)
            ->where('creator_ip_hash', $ipHash)
            ->exists()) {
            throw MandateRefusal::make('activity', 'anim.api.ip_limit');
        }

        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;

        $activity = $this->persist($this->attributes($type, $data) + [
            'character_id' => null,
            'is_anonymous' => true,
            'organizer_name' => $pseudo,
            'password_hash' => Hash::make($data['password']),
            'creator_ip_hash' => $ipHash,
            'organizer_email' => $email,
        ]);

        // Une adresse n'est utilisée qu'une fois confirmée (D29) : ce message ne porte que le lien signé.
        if ($email !== null) {
            $this->mailer->sendConfirmation($activity);
        }

        return $activity;
    }

    /** Confirmation de l'adresse par le lien signé (D29). Faux si l'activité, l'adresse ou l'empreinte ne correspondent plus. */
    public function confirmEmail(string $slug, string $hash): bool
    {
        $activity = AnimDecodeActivity::query()->where('slug', $slug)->where('is_anonymous', true)->first();
        if (! $activity || $activity->isClosed() || $activity->organizer_email === null) {
            return false;
        }
        if (! hash_equals(sha1($activity->organizer_email), $hash)) {
            return false;
        }
        if ($activity->organizer_email_verified_at === null) {
            $activity->forceFill(['organizer_email_verified_at' => Carbon::now()])->save();
        }

        return true;
    }

    /**
     * Scelle un premier coffre (activité neuve). Pas de second coffre tant que le premier tient : ensuite c'est la
     * découverte du code qui en scelle un nouveau (DecodeProposals).
     *
     * @throws MandateRefusal
     */
    public function generateSecret(Character|DecodeActor $actor, AnimDecodeActivity $activity): AnimDecodeSecret
    {
        $this->authorize($actor, $activity);
        if ($activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_closed');
        }
        if ($this->pendingSecret($activity)) {
            throw MandateRefusal::make('activity', 'anim.api.secret_pending');
        }

        return DB::transaction(fn () => $this->seal($activity));
    }

    /** Coffre en cours (non percé), ou null. */
    public function pendingSecret(AnimDecodeActivity $activity): ?AnimDecodeSecret
    {
        return $activity->secrets()->where('status', false)->latest('id')->first();
    }

    /**
     * Scelle un nouveau coffre et met l'activité en route. Pose `launched_at` au PREMIER lancement seulement. Appelée aussi
     * par DecodeProposals quand un code est percé.
     */
    public function seal(AnimDecodeActivity $activity, string $status = AnimDecodeActivity::LAUNCHED): AnimDecodeSecret
    {
        $secret = $activity->secrets()->create(['code' => self::randomCode(), 'status' => false]);

        $activity->forceFill([
            'status' => $status,
            'launched_at' => $activity->launched_at ?? Carbon::now(),
        ])->save();

        return $secret;
    }

    /**
     * Rouvre une activité PRIVÉE close. Jamais une activité sans compte : sa clôture a effacé ses données et son mot de passe
     * (D19).
     *
     * @throws MandateRefusal
     */
    public function reopen(Character|DecodeActor $actor, AnimDecodeActivity $activity): AnimDecodeActivity
    {
        $this->authorize($actor, $activity);
        if ($activity->is_anonymous) {
            throw MandateRefusal::make('activity', 'anim.api.reopen_anonymous');
        }
        if (! $activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_not_closed');
        }
        if ($this->hasActiveOfType(DecodeActor::of($actor)->character, $activity->type, $activity)) {
            throw MandateRefusal::make('activity', 'anim.api.reopen_blocked');
        }

        return DB::transaction(function () use ($activity) {
            $activity->forceFill(['status' => AnimDecodeActivity::LAUNCHED, 'closed_at' => null])->save();
            // Close avant le premier coffre : rien à reprendre, on en scelle un.
            if (! $this->pendingSecret($activity)) {
                $this->seal($activity);
            }

            return $activity->refresh();
        });
    }

    /** @throws MandateRefusal */
    public function close(Character|DecodeActor $actor, AnimDecodeActivity $activity): AnimDecodeActivity
    {
        $this->authorize($actor, $activity);
        if ($activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_closed');
        }

        return $this->closing->close($activity);
    }

    /** Fermeture sans acteur : les 30 jours sans action (anim:close-inactive) ou l'administrateur du site (D13). */
    public function closeNow(AnimDecodeActivity $activity): AnimDecodeActivity
    {
        return $this->closing->close($activity);
    }

    /**
     * Greg, tour 03 : on ne supprime qu'une activité PRIVÉE CLOSE, avec ou sans propositions (la page publique d'une activité
     * close reste lisible, un lien posté sur le forum ne casse pas tant qu'on ne supprime pas). Une activité SANS COMPTE ne
     * se supprime pas par son auteur : sa page de résumé vit 5 ans, seule l'administrateur du site la retire (D13).
     *
     * @throws MandateRefusal
     */
    public function delete(Character|DecodeActor $actor, AnimDecodeActivity $activity): void
    {
        $this->authorize($actor, $activity);
        if ($activity->is_anonymous) {
            throw MandateRefusal::make('activity', 'anim.api.delete_anonymous');
        }
        if (! $activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.delete_requires_closed');
        }

        $activity->delete();
    }

    /** Suppression par l'administrateur du site (D13) : une activité sans compte, close ou non ; la confirmation est dans l'écran. */
    public function deleteByAdmin(AnimDecodeActivity $activity): void
    {
        $activity->delete();
    }

    /**
     * Dernière action connue : la plus récente parmi la dernière proposition, le lancement et la dernière modification de
     * l'activité (création, réouverture, nouveau coffre). Une réouverture relance donc l'horloge des 30 jours.
     */
    public function lastActionAt(AnimDecodeActivity $activity): Carbon
    {
        $lastProposal = $activity->secrets()->with(['proposals' => fn ($q) => $q->latest('id')->limit(1)])->get()
            ->flatMap->proposals->max('created_at');

        return collect([$lastProposal, $activity->launched_at, $activity->updated_at, $activity->created_at])
            ->filter()
            ->max();
    }

    /** Cinq chiffres, `random_int` (jamais `rand()`). */
    public static function randomCode(): string
    {
        $digits = '';
        for ($i = 0; $i < DecodeScoring::LENGTH; $i++) {
            $digits .= random_int(0, 9);
        }

        return $digits;
    }

    /** @throws MandateRefusal 403 */
    public function authorize(Character|DecodeActor $actor, AnimDecodeActivity $activity): void
    {
        if (! DecodeActor::of($actor)->owns($activity)) {
            throw self::forbidden();
        }
    }

    /** Type ouvert, slug valable et libre (D14, D27), un seul niveau de lieu (D1). */
    private function assertCreatable(AnimActivityType $type, array $data): void
    {
        if (! $type->is_active) {
            throw MandateRefusal::make('type', 'anim.api.type_inactive');
        }

        $slug = (string) ($data['slug'] ?? '');
        if (! DecodeSlug::isValid($slug)) {
            throw MandateRefusal::make('slug', 'anim.api.slug_invalid', ['min' => DecodeSlug::MIN, 'max' => DecodeSlug::MAX]);
        }
        if (DecodeSlug::taken($slug)) {
            throw MandateRefusal::make('slug', 'anim.api.slug_taken');
        }

        $levels = array_filter([$data['kingdom_id'] ?? null, $data['province_id'] ?? null, $data['city_id'] ?? null]);
        if (count($levels) > 1) {
            throw MandateRefusal::make('place', 'anim.api.place_exclusive');
        }
    }

    /** Colonnes communes aux deux modes. */
    private function attributes(AnimActivityType $type, array $data): array
    {
        return [
            'slug' => $data['slug'],
            'name' => $data['name'],
            'activity_type_id' => $type->id,
            'kingdom_id' => $data['kingdom_id'] ?? null,
            'province_id' => $data['province_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'status' => AnimDecodeActivity::NEW,
        ];
    }

    /** @throws MandateRefusal slug pris entre la vérification et l'écriture */
    private function persist(array $attributes): AnimDecodeActivity
    {
        try {
            return AnimDecodeActivity::create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw MandateRefusal::make('slug', 'anim.api.slug_taken');
        }
    }

    /** Une autre activité du même type, non close, pour ce personnage (hors l'activité donnée). */
    private function hasActiveOfType(Character $actor, AnimActivityType $type, ?AnimDecodeActivity $except = null): bool
    {
        return AnimDecodeActivity::query()
            ->where('character_id', $actor->id)
            ->where('activity_type_id', $type->id)
            ->where('status', '!=', AnimDecodeActivity::CLOSED)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
    }

    private static function forbidden(): MandateRefusal
    {
        return MandateRefusal::make('activity', 'anim.api.not_owner')->status(403);
    }
}
