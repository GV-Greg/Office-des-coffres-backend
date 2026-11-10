<?php

namespace App\Services;

use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;

/**
 * Lecture d'une grille des lots : la page publique ET l'écran de l'organisateur, une seule forme, deux sources.
 *
 *  - Grille vivante, privée ou sans compte : les tables (lots).
 *  - Grille SANS COMPTE close : la PHOTO FIGÉE (`closing_snapshot`) — les données personnelles ont été effacées.
 *
 * Règles de visibilité du nom des lots :
 *  - Avant `drawed` (new/incomplete/filled) : réservé à l'organisateur via l'écran de gestion.
 *  - `drawed`/`confirmed` : la place est connue, mais le nom du lot est MASQUÉ pour les cases non choisies (suspens).
 *  - `closed` : tout est révélé.
 */
class RewardsGridReader
{
    public function read(AnimRewardsGrid $grid, bool $forOwner = false): array
    {
        $grid->loadMissing(['character', 'kingdom', 'province', 'city', 'type']);

        if ($grid->isClosed() && $grid->is_anonymous && $grid->closing_snapshot) {
            return $this->fromSnapshot($grid);
        }

        $lots = $grid->lots()->get();

        $data = $this->base($grid) + [
            'lots' => $this->formatLots($lots, $grid->status, $forOwner),
        ];

        if ($forOwner) {
            $data['can_reopen'] = ! $grid->is_anonymous && $grid->isClosed();
            if ($grid->is_anonymous) {
                $data['email'] = ['set' => $grid->organizer_email !== null, 'verified' => $grid->hasVerifiedEmail()];
            }
        }

        return $data;
    }

    /**
     * Photo figée pour la clôture d'une grille SANS COMPTE.
     * Les lots restent en base ; le snapshot garde le nom/organisateur/lieu pour 5 ans sans les FK.
     */
    public function snapshot(AnimRewardsGrid $grid): array
    {
        $grid->loadMissing(['kingdom', 'province', 'city']);

        return [
            'name' => $grid->name,
            'organizer' => $this->organizer($grid),
            'place' => $this->place($grid),
            'width' => $grid->width,
            'height' => $grid->height,
        ];
    }

    private function base(AnimRewardsGrid $grid): array
    {
        return [
            'id' => $grid->id,
            'slug' => $grid->slug,
            'name' => $grid->name,
            'type' => $grid->type->key,
            'status' => $grid->status,
            'mode' => $grid->is_anonymous ? 'anonymous' : 'private',
            'organizer' => $this->organizer($grid),
            'place' => $this->place($grid),
            'width' => $grid->width,
            'height' => $grid->height,
            'drawn_at' => $grid->drawn_at?->toIso8601String(),
            'confirmed_at' => $grid->confirmed_at?->toIso8601String(),
            'closed_at' => $grid->closed_at?->toIso8601String(),
        ];
    }

    private function fromSnapshot(AnimRewardsGrid $grid): array
    {
        $snapshot = $grid->closing_snapshot;
        $lots = $grid->lots()->get();

        return [
            'id' => $grid->id,
            'slug' => $grid->slug,
            'name' => $snapshot['name'] ?? $grid->name,
            'type' => $grid->type->key,
            'status' => $grid->status,
            'mode' => 'anonymous',
            'organizer' => $snapshot['organizer'] ?? null,
            'place' => $snapshot['place'] ?? null,
            'width' => $snapshot['width'] ?? $grid->width,
            'height' => $snapshot['height'] ?? $grid->height,
            'drawn_at' => $grid->drawn_at?->toIso8601String(),
            'confirmed_at' => $grid->confirmed_at?->toIso8601String(),
            'closed_at' => $grid->closed_at?->toIso8601String(),
            'lots' => $this->formatLots($lots, AnimRewardsGrid::CLOSED, false),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, AnimRewardsList>  $lots
     */
    private function formatLots($lots, string $status, bool $forOwner): array
    {
        $revealNames = $status === AnimRewardsGrid::CLOSED || $forOwner;
        $isPublicPhase = in_array($status, AnimRewardsGrid::PUBLIC_STATUSES, true);

        return $lots->map(function (AnimRewardsList $lot) use ($revealNames, $isPublicPhase, $forOwner) {
            $data = [
                'id' => $lot->id,
                'place' => $lot->place,
                'player' => $lot->player,
                'is_taken' => $lot->is_taken,
            ];

            // Le nom du lot est masqué en phase publique tant que personne n'a choisi la case (suspens).
            if ($isPublicPhase) {
                $data['name'] = ($revealNames || $lot->player !== null) ? $lot->name : null;
            } else {
                // Avant le tirage, seul l'organisateur voit les lots.
                $data['name'] = $forOwner ? $lot->name : null;
            }

            return $data;
        })->all();
    }

    private function organizer(AnimRewardsGrid $grid): ?string
    {
        return $grid->is_anonymous ? $grid->organizer_name : $grid->character?->pseudo;
    }

    private function place(AnimRewardsGrid $grid): ?array
    {
        if ($grid->kingdom) {
            return ['kind' => 'kingdom', 'id' => $grid->kingdom->id, 'name' => $grid->kingdom->kingdom_name];
        }
        if ($grid->province) {
            return ['kind' => 'province', 'id' => $grid->province->id, 'name' => $grid->province->province_name];
        }

        return $grid->city
            ? ['kind' => 'city', 'id' => $grid->city->id, 'name' => $grid->city->city_name]
            : null;
    }
}
