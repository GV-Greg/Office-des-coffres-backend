<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Database\Eloquent\Model;

/**
 * Celui qui agit sur une activité du module Animation : un PERSONNAGE (mode privé, propriété par `character_id`) ou un
 * auteur SANS COMPTE qui vient de prouver son mot de passe (AnimAccess). Les gestes des workflows ne connaissent que
 * cette abstraction : la vérification de propriété reste en tête de chacun, quel que soit le mode.
 *
 * `proven` est typé sur `Model` pour couvrir tous les types d'activité (AnimDecodeActivity, AnimRewardsGrid…) sans
 * couplage à un modèle précis.
 */
final class AnimActor
{
    private function __construct(
        public readonly ?Character $character,
        public readonly ?Model $proven,
    ) {}

    public static function forCharacter(Character $character): self
    {
        return new self($character, null);
    }

    /** L'activité dont le mot de passe vient d'être vérifié — jamais construit sans cette preuve. */
    public static function forAnonymous(Model $activity): self
    {
        return new self(null, $activity);
    }

    public static function of(Character|self $actor): self
    {
        return $actor instanceof self ? $actor : self::forCharacter($actor);
    }

    public function owns(Model $activity): bool
    {
        if ($this->character !== null) {
            return ! $activity->is_anonymous
                && $activity->character_id !== null
                && $activity->character_id === $this->character->id;
        }

        return $this->proven !== null && $activity->is_anonymous && $this->proven->is($activity);
    }
}
