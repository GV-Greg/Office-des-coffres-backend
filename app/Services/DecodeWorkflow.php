<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeSecret;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Les Coffres Scellés — gestes de l'organisateur (brief admin/modules/animation/brief-coffres-scelles.md ;
 * fil admin/echanges/animation-coffres-scelles). SEUL écrivain des activités et des secrets ; le
 * contrôleur ne valide que la forme.
 *
 * 🔴 Autorisation PAR PROPRIÉTÉ, pas par poste : `activity.character_id === actor.id`, vérifiée en
 * premier dans chaque geste de gestion (premier module du projet sans Spatie ni mandat). Un refus de
 * propriété est un 403 ; les autres refus restent des 422 au contrat de MandateApiErrors.
 *
 * Règles tranchées par Greg (tour 03 du fil) : une activité ne se supprime que CLOSE ; une
 * réouverture est refusée si une autre activité du même type est déjà active pour le personnage.
 */
class DecodeWorkflow
{
    /**
     * Le personnage du compte qui possède l'activité. Les routes `decode-activities/{activity}` ne
     * portent pas de personnage : l'acteur est l'organisateur de l'activité, à condition qu'il
     * appartienne bien au compte authentifié.
     *
     * @throws MandateRefusal 403
     */
    public function ownerOf(User $user, AnimDecodeActivity $activity): Character
    {
        $owner = $activity->character_id
            ? $user->characters()->whereKey($activity->character_id)->first()
            : null;

        return $owner ?? throw self::forbidden();
    }

    /**
     * @param  array{name: string, description?: ?string, province_id?: ?int, city_id?: ?int}  $data
     *
     * @throws MandateRefusal
     */
    public function create(Character $actor, AnimActivityType $type, array $data): AnimDecodeActivity
    {
        if (! $type->is_active) {
            throw MandateRefusal::make('type', 'anim.api.type_inactive');
        }
        if (! empty($data['province_id']) && ! empty($data['city_id'])) {
            throw MandateRefusal::make('city_id', 'anim.api.place_exclusive');
        }
        if ($this->hasActiveOfType($actor, $type)) {
            throw MandateRefusal::make('name', 'anim.api.activity_already_active');
        }

        return AnimDecodeActivity::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'character_id' => $actor->id,
            'activity_type_id' => $type->id,
            'province_id' => $data['province_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'status' => AnimDecodeActivity::NEW,
        ]);
    }

    /**
     * Scelle un premier coffre (activité neuve). Pas de second coffre tant que le premier tient :
     * ensuite c'est la découverte du code qui en scelle un nouveau (DecodeProposals).
     *
     * @throws MandateRefusal
     */
    public function generateSecret(Character $actor, AnimDecodeActivity $activity): AnimDecodeSecret
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

    /**
     * Coffre en cours (non percé), ou null.
     */
    public function pendingSecret(AnimDecodeActivity $activity): ?AnimDecodeSecret
    {
        return $activity->secrets()->where('status', false)->latest('id')->first();
    }

    /**
     * Scelle un nouveau coffre et met l'activité en route. Pose `launched_at` au PREMIER lancement
     * seulement. Appelée aussi par DecodeProposals quand un code est percé.
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

    /** @throws MandateRefusal */
    public function reopen(Character $actor, AnimDecodeActivity $activity): AnimDecodeActivity
    {
        $this->authorize($actor, $activity);
        if (! $activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_not_closed');
        }
        if ($this->hasActiveOfType($actor, $activity->type, $activity)) {
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
    public function close(Character $actor, AnimDecodeActivity $activity): AnimDecodeActivity
    {
        $this->authorize($actor, $activity);
        if ($activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_closed');
        }

        return $this->closeNow($activity);
    }

    /** Fermeture sans acteur : la tâche des 30 jours (anim:close-inactive). */
    public function closeNow(AnimDecodeActivity $activity): AnimDecodeActivity
    {
        $activity->forceFill(['status' => AnimDecodeActivity::CLOSED, 'closed_at' => Carbon::now()])->save();

        return $activity;
    }

    /**
     * Greg, tour 03 : on ne supprime qu'une activité CLOSE, avec ou sans propositions. La page
     * publique d'une activité close reste lisible, donc un lien déjà posté sur le forum ne casse pas
     * tant qu'on ne supprime pas.
     *
     * @throws MandateRefusal
     */
    public function delete(Character $actor, AnimDecodeActivity $activity): void
    {
        $this->authorize($actor, $activity);
        if (! $activity->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.delete_requires_closed');
        }

        $activity->delete();
    }

    /**
     * Dernière action connue : la plus récente parmi la dernière proposition, le lancement et la
     * dernière modification de l'activité (création, réouverture, nouveau coffre). Une réouverture
     * relance donc l'horloge des 30 jours.
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

    /** @throws MandateRefusal 403 */
    public function authorize(Character $actor, AnimDecodeActivity $activity): void
    {
        if ($activity->character_id === null || $activity->character_id !== $actor->id) {
            throw self::forbidden();
        }
    }

    private static function forbidden(): MandateRefusal
    {
        return MandateRefusal::make('activity', 'anim.api.not_owner')->status(403);
    }
}
