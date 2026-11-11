<?php

namespace App\Support;

use App\Models\AnimDecodeActivity;
use App\Models\AnimRewardsGrid;
use App\Services\DecodeWorkflow;
use App\Services\RewardsGridWorkflow;

/**
 * Les activités du module Animation qui ont un cycle de vie commun : la clôture après 30 jours sans action (`anim:close-inactive`) et la purge
 * du résumé d'une activité SANS COMPTE close depuis 5 ans (`anim:purge-closed`). Ajouter un type d'activité = une ligne ici, pas une commande
 * de plus (voir `admin/strategies/animation.md`).
 *
 * Chaque modèle porte ses durées en constantes (`INACTIVITY_DAYS`, `RETENTION_YEARS`) — jamais un `config/*.php`, absent du cache en prod
 * (incident du 04/10/2026) — et chaque service `lastActionAt()` et `closeNow()`. Ces durées sont des PROMESSES aux joueurs : les changer,
 * c'est d'abord changer le texte de la politique.
 */
final class AnimKinds
{
    /**
     * @return list<array{key: string, label: string, model: class-string, workflow: class-string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'decode', 'label' => 'Décode le code', 'model' => AnimDecodeActivity::class, 'workflow' => DecodeWorkflow::class],
            ['key' => 'rewards', 'label' => 'Grille des lots', 'model' => AnimRewardsGrid::class, 'workflow' => RewardsGridWorkflow::class],
        ];
    }
}
