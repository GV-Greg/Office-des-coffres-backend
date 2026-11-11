<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\AnimActivityType;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Models\Character;
use App\Models\User;
use App\Support\AnimIp;
use App\Support\AnimPassword;
use App\Support\AnimSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Grille des lots — gestes de l'organisateur. SEUL écrivain des grilles et des lots ; les contrôleurs ne valident que
 * la forme.
 *
 * 🔴 Autorisation PAR PROPRIÉTÉ, jamais par poste (premier module du projet sans Spatie ni mandat) : un geste de
 * gestion reçoit un `AnimActor` — un personnage (mode privé) ou un auteur sans compte qui a prouvé son mot de passe
 * — et vérifie la propriété EN PREMIER. Un refus de propriété est un 403 ; les autres refus restent des 422 au
 * contrat d'AnimApiErrors.
 *
 * Automate d'état : new → incomplete → filled → confirmed → closed.
 *  - new : 0 lots.
 *  - incomplete : 1 ≤ lots < width*height.
 *  - filled : lots == width*height.
 *  - confirmed : tirage + confirmation en un geste, joueurs peuvent être assignés aux cases.
 *  - closed : terminée.
 *  (L'état `drawed` existe encore en base pour la compatibilité de données anciennes ; `confirm()`
 *   peut encore en sortir. Les nouvelles grilles ne le traversent plus.)
 *
 * Règles :
 *  - Une grille PRIVÉE ne se supprime que close ; réouverture si aucune autre grille active du même type.
 *  - Une grille SANS COMPTE ne se rouvre jamais et son auteur ne la supprime pas (l'administrateur le peut).
 *  - Une seule grille sans compte non close par connexion (empreinte d'IP).
 *  - Catégorie C : `character_id` nullOnDelete — une suppression de compte NE SUPPRIME PAS la grille.
 */
class RewardsGridWorkflow
{
    public function __construct(
        private readonly RewardsGridClosing $closing,
    ) {}

    /**
     * Le personnage du compte qui possède une grille PRIVÉE.
     *
     * @throws MandateRefusal 403
     */
    public function ownerOf(User $user, AnimRewardsGrid $grid): Character
    {
        $owner = (! $grid->is_anonymous && $grid->character_id)
            ? $user->characters()->whereKey($grid->character_id)->first()
            : null;

        return $owner ?? throw self::forbidden();
    }

    /**
     * Création PRIVÉE (liée au personnage).
     *
     * @param  array{slug: string, name: string, width: int, height: int, kingdom_id?: ?int, province_id?: ?int, city_id?: ?int}  $data
     *
     * @throws MandateRefusal
     */
    public function create(Character $actor, AnimActivityType $type, array $data): AnimRewardsGrid
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
     * Création SANS COMPTE : slug, pseudo OBLIGATOIRE, mot de passe choisi, email FACULTATIF confirmé par un lien.
     * `$ipHash` est l'empreinte de la connexion (AnimIp), nulle si l'adresse n'est pas fiable.
     *
     * @param  array{slug: string, name: string, width: int, height: int, organizer_name: ?string, password: string, email?: ?string, kingdom_id?: ?int, province_id?: ?int, city_id?: ?int}  $data
     *
     * @throws MandateRefusal
     */
    public function createAnonymous(AnimActivityType $type, array $data, ?string $ipHash): AnimRewardsGrid
    {
        $this->assertCreatable($type, $data);

        $pseudo = trim((string) ($data['organizer_name'] ?? ''));
        if ($pseudo === '') {
            throw MandateRefusal::make('organizer_name', 'anim.api.organizer_required');
        }
        AnimPassword::assertValid((string) ($data['password'] ?? ''), $data['slug'], $pseudo);

        // UNE SEULE grille sans compte non close par connexion (empreinte d'IP).
        if ($ipHash !== null && AnimRewardsGrid::query()
            ->where('is_anonymous', true)
            ->where('status', '!=', AnimRewardsGrid::CLOSED)
            ->where('creator_ip_hash', $ipHash)
            ->exists()) {
            throw MandateRefusal::make('activity', 'anim.api.ip_limit');
        }

        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;

        return $this->persist($this->attributes($type, $data) + [
            'character_id' => null,
            'is_anonymous' => true,
            'organizer_name' => $pseudo,
            'password_hash' => Hash::make($data['password']),
            'creator_ip_hash' => $ipHash,
            'organizer_email' => $email,
            // Pas d'email de confirmation ici : « Hors scope: Notifications email » pour ce module.
        ]);
    }

    /** Confirmation de l'adresse par le lien signé. Faux si la grille, l'adresse ou l'empreinte ne correspondent plus. */
    public function confirmEmail(string $slug, string $hash): bool
    {
        $grid = AnimRewardsGrid::query()->where('slug', $slug)->where('is_anonymous', true)->first();
        if (! $grid || $grid->isClosed() || $grid->organizer_email === null) {
            return false;
        }
        if (! hash_equals(sha1($grid->organizer_email), $hash)) {
            return false;
        }
        if ($grid->organizer_email_verified_at === null) {
            $grid->forceFill(['organizer_email_verified_at' => Carbon::now()])->save();
        }

        return true;
    }

    /**
     * Ajoute un lot à la grille. Autorisé seulement en new/incomplete/filled.
     *
     * @throws MandateRefusal
     */
    public function addLot(Character|AnimActor $actor, AnimRewardsGrid $grid, string $name): AnimRewardsList
    {
        $this->authorize($actor, $grid);
        if (! $grid->isEditable()) {
            throw MandateRefusal::make('activity', 'anim.api.grid_not_editable');
        }
        if ($grid->lots()->count() >= $grid->capacity()) {
            throw MandateRefusal::make('name', 'anim.api.grid_full');
        }

        return DB::transaction(function () use ($grid, $name) {
            $lot = $grid->lots()->create(['name' => $name]);
            $this->updateStatusAfterLotChange($grid);

            return $lot;
        });
    }

    /**
     * Ajoute PLUSIEURS lots en un seul geste (une requête pour une liste collée ou une quantité : une requête par lot épuiserait la limite de
     * débit de l'API). TOUT OU RIEN : si la liste ne tient pas dans les cases libres, rien n'est ajouté et le refus est celui de `addLot`
     * (`grid_full`), pour que l'écran ne montre jamais une grille à moitié remplie par un échec. Un doublon est permis : deux lots peuvent
     * porter le même nom (une quantité n'est que cela).
     *
     * @param  list<string>  $names
     * @return list<AnimRewardsList>
     *
     * @throws MandateRefusal
     */
    public function addLots(Character|AnimActor $actor, AnimRewardsGrid $grid, array $names): array
    {
        $this->authorize($actor, $grid);
        if (! $grid->isEditable()) {
            throw MandateRefusal::make('activity', 'anim.api.grid_not_editable');
        }
        if ($grid->lots()->count() + count($names) > $grid->capacity()) {
            throw MandateRefusal::make('name', 'anim.api.grid_full');
        }

        return DB::transaction(function () use ($grid, $names) {
            $created = [];
            foreach ($names as $name) {
                $created[] = $grid->lots()->create(['name' => $name]);
            }
            $this->updateStatusAfterLotChange($grid);

            return $created;
        });
    }

    /**
     * Retire un lot de la grille. Autorisé seulement en new/incomplete/filled.
     *
     * @throws MandateRefusal
     */
    public function removeLot(Character|AnimActor $actor, AnimRewardsGrid $grid, AnimRewardsList $lot): void
    {
        $this->authorize($actor, $grid);
        if (! $grid->isEditable()) {
            throw MandateRefusal::make('activity', 'anim.api.grid_not_editable');
        }
        if ($lot->grid_id !== $grid->id) {
            throw self::forbidden();
        }

        DB::transaction(function () use ($grid, $lot) {
            $lot->delete();
            $this->updateStatusAfterLotChange($grid->fresh());
        });
    }

    /**
     * Tire les positions au sort ET confirme en un seul geste. Autorisé seulement en `filled`.
     * Les deux horodatages (`drawn_at`, `confirmed_at`) sont posés simultanément.
     *
     * @throws MandateRefusal
     */
    public function draw(Character|AnimActor $actor, AnimRewardsGrid $grid): AnimRewardsGrid
    {
        $this->authorize($actor, $grid);
        if ($grid->status !== AnimRewardsGrid::FILLED) {
            throw MandateRefusal::make('activity', 'anim.api.grid_not_filled');
        }

        return DB::transaction(function () use ($grid) {
            $lots = $grid->lots()->orderBy('id')->get();
            $places = range(1, $grid->capacity());
            shuffle($places);

            foreach ($lots as $i => $lot) {
                $lot->forceFill(['place' => $places[$i]])->save();
            }

            $now = Carbon::now();
            $grid->forceFill([
                'status' => AnimRewardsGrid::CONFIRMED,
                'drawn_at' => $now,
                'confirmed_at' => $now,
            ])->save();

            return $grid->refresh();
        });
    }

    /**
     * Assigne (ou désassigne) un joueur à une case. `player = null` retire l'assignation. Autorisé seulement en
     * `confirmed`. `is_taken` reste inchangé : c'est un geste séparé (lot remis).
     *
     * @throws MandateRefusal
     */
    public function assign(Character|AnimActor $actor, AnimRewardsGrid $grid, AnimRewardsList $lot, ?string $player): AnimRewardsList
    {
        $this->authorize($actor, $grid);
        if ($grid->status !== AnimRewardsGrid::CONFIRMED) {
            throw MandateRefusal::make('activity', 'anim.api.grid_not_confirmed');
        }
        if ($lot->grid_id !== $grid->id) {
            throw self::forbidden();
        }

        $lot->forceFill(['player' => $player])->save();

        return $lot;
    }

    /**
     * Marque un lot comme remis (`is_taken = true`) ou non remis (`is_taken = false`). Autorisé en `confirmed` ou
     * `closed`.
     *
     * @throws MandateRefusal
     */
    public function markTaken(Character|AnimActor $actor, AnimRewardsGrid $grid, AnimRewardsList $lot, bool $taken): AnimRewardsList
    {
        $this->authorize($actor, $grid);
        if (! in_array($grid->status, [AnimRewardsGrid::CONFIRMED, AnimRewardsGrid::CLOSED], true)) {
            throw MandateRefusal::make('activity', 'anim.api.grid_not_confirmed');
        }
        if ($lot->grid_id !== $grid->id) {
            throw self::forbidden();
        }

        $lot->forceFill(['is_taken' => $taken])->save();

        return $lot;
    }

    /** @throws MandateRefusal */
    public function close(Character|AnimActor $actor, AnimRewardsGrid $grid): AnimRewardsGrid
    {
        $this->authorize($actor, $grid);
        if ($grid->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_closed');
        }

        return $this->closing->close($grid);
    }

    /** Fermeture sans acteur : les 30 jours sans action (`anim:close-inactive`) ou l'administrateur du site. */
    public function closeNow(AnimRewardsGrid $grid): AnimRewardsGrid
    {
        return $this->closing->close($grid);
    }

    /**
     * Rouvre une grille PRIVÉE close. Jamais une grille sans compte.
     *
     * @throws MandateRefusal
     */
    public function reopen(Character|AnimActor $actor, AnimRewardsGrid $grid): AnimRewardsGrid
    {
        $this->authorize($actor, $grid);
        if ($grid->is_anonymous) {
            throw MandateRefusal::make('activity', 'anim.api.reopen_anonymous');
        }
        if (! $grid->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.activity_not_closed');
        }
        if ($this->hasActiveOfType(AnimActor::of($actor)->character, $grid->type, $grid)) {
            throw MandateRefusal::make('activity', 'anim.api.reopen_blocked');
        }

        $grid->forceFill(['status' => AnimRewardsGrid::CONFIRMED, 'closed_at' => null])->save();

        return $grid->refresh();
    }

    /**
     * Supprime une grille PRIVÉE close (jamais une grille sans compte ; l'admin peut supprimer n'importe quoi).
     *
     * @throws MandateRefusal
     */
    public function delete(Character|AnimActor $actor, AnimRewardsGrid $grid): void
    {
        $this->authorize($actor, $grid);
        if ($grid->is_anonymous) {
            throw MandateRefusal::make('activity', 'anim.api.delete_anonymous');
        }
        if (! $grid->isClosed()) {
            throw MandateRefusal::make('activity', 'anim.api.delete_requires_closed');
        }

        $grid->delete();
    }

    /** Suppression par l'administrateur du site : une grille sans compte, close ou non. */
    public function deleteByAdmin(AnimRewardsGrid $grid): void
    {
        $grid->delete();
    }

    /**
     * Dernière action connue : la plus récente parmi la création d'un lot, le tirage et la dernière modification de
     * la grille. Une réouverture relance donc l'horloge des 30 jours.
     */
    public function lastActionAt(AnimRewardsGrid $grid): Carbon
    {
        $lastLot = $grid->lots()->max('created_at');

        return collect([$lastLot, $grid->drawn_at, $grid->updated_at, $grid->created_at])
            ->filter()
            ->max();
    }

    /** @throws MandateRefusal 403 */
    public function authorize(Character|AnimActor $actor, AnimRewardsGrid $grid): void
    {
        if (! AnimActor::of($actor)->owns($grid)) {
            throw self::forbidden();
        }
    }

    /** Type ouvert, slug valable et libre, dimensions valides, un seul niveau de lieu. */
    private function assertCreatable(AnimActivityType $type, array $data): void
    {
        if (! $type->is_active) {
            throw MandateRefusal::make('type', 'anim.api.type_inactive');
        }

        $slug = (string) ($data['slug'] ?? '');
        if (! AnimSlug::isValid($slug)) {
            throw MandateRefusal::make('slug', 'anim.api.slug_invalid', ['min' => AnimSlug::MIN, 'max' => AnimSlug::MAX]);
        }
        if (AnimSlug::taken($slug, AnimRewardsGrid::class)) {
            throw MandateRefusal::make('slug', 'anim.api.slug_taken');
        }

        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        if ($width < 1 || $height < 1 || $width > 10 || $height > 10) {
            throw MandateRefusal::make('dimensions', 'anim.api.grid_dimensions_invalid');
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
            'width' => $data['width'],
            'height' => $data['height'],
            'kingdom_id' => $data['kingdom_id'] ?? null,
            'province_id' => $data['province_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'status' => AnimRewardsGrid::NEW,
        ];
    }

    /** @throws MandateRefusal slug pris entre la vérification et l'écriture */
    private function persist(array $attributes): AnimRewardsGrid
    {
        try {
            return AnimRewardsGrid::create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw MandateRefusal::make('slug', 'anim.api.slug_taken');
        }
    }

    /** Met à jour le statut de la grille après ajout/suppression d'un lot. */
    private function updateStatusAfterLotChange(AnimRewardsGrid $grid): void
    {
        $count = $grid->lots()->count();
        $capacity = $grid->capacity();

        $status = match (true) {
            $count === 0 => AnimRewardsGrid::NEW,
            $count >= $capacity => AnimRewardsGrid::FILLED,
            default => AnimRewardsGrid::INCOMPLETE,
        };

        if ($grid->status !== $status) {
            $grid->forceFill(['status' => $status])->save();
        }
    }

    /** Une autre grille du même type, non close, pour ce personnage (hors la grille donnée). */
    private function hasActiveOfType(Character $actor, AnimActivityType $type, ?AnimRewardsGrid $except = null): bool
    {
        return AnimRewardsGrid::query()
            ->where('character_id', $actor->id)
            ->where('activity_type_id', $type->id)
            ->where('status', '!=', AnimRewardsGrid::CLOSED)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
    }

    private static function forbidden(): MandateRefusal
    {
        return MandateRefusal::make('activity', 'anim.api.not_owner')->status(403);
    }
}
