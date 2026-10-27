<?php

namespace App\Services;

use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;

/**
 * Lecture des Coffres Scellés : la page publique ET l'écran de l'organisateur, une seule forme.
 *
 * 🔴 Le code du coffre EN COURS n'en sort que pour l'organisateur (`$forOwner`, tour 03 du fil,
 * Q3 : « affiché à l'organisateur seul, pour le moment »). La page publique ne montre un code
 * qu'une fois le coffre percé, avec son découvreur. Gardé par DecodeActivityApiTest dans les deux sens.
 */
class DecodeActivityReader
{
    public function read(AnimDecodeActivity $activity, bool $forOwner = false): array
    {
        $activity->loadMissing(['character', 'province', 'city', 'type']);
        $secrets = $activity->secrets()->with('proposals')->get();

        $current = $secrets->first(fn (AnimDecodeSecret $s) => ! $s->status);
        $opened = [];
        foreach ($secrets as $i => $secret) {
            if ($secret->status) {
                $opened[] = $this->opened($secret, $i + 1);
            }
        }

        return [
            'id' => $activity->id,
            'name' => $activity->name,
            'description' => $activity->description,
            'type' => $activity->type->key,
            'status' => $activity->status,
            'organizer' => $activity->character?->pseudo,
            'place' => $this->place($activity),
            'launched_at' => $activity->launched_at?->toIso8601String(),
            'closed_at' => $activity->closed_at?->toIso8601String(),
            'current' => $current ? $this->current($current, $secrets->search($current) + 1, $forOwner) : null,
            'opened' => $opened,
            'ranking' => $this->ranking($secrets),
        ];
    }

    /** Classement cumulé sur tous les coffres : points par joueur, du plus haut au plus bas. */
    private function ranking($secrets): array
    {
        return $secrets->flatMap->proposals
            ->groupBy('player')
            ->map(fn ($proposals, $player) => ['player' => $player, 'points' => $proposals->sum('points')])
            ->sort(fn ($a, $b) => [$b['points'], $a['player']] <=> [$a['points'], $b['player']])
            ->values()
            ->all();
    }

    private function current(AnimDecodeSecret $secret, int $number, bool $forOwner): array
    {
        $data = [
            'number' => $number,
            'proposals' => $secret->proposals->map(fn (AnimDecodeProposal $p) => $this->proposal($p))->all(),
        ];

        // L'organisateur seul : id (pour saisir une proposition) et code (pour vérifier).
        return $forOwner ? ['id' => $secret->id, 'code' => $secret->code] + $data : $data;
    }

    private function opened(AnimDecodeSecret $secret, int $number): array
    {
        $found = $secret->proposals->first(fn (AnimDecodeProposal $p) => $p->isFound());

        return [
            'number' => $number,
            'code' => $secret->code,
            'discoverer' => $found?->player,
            'found_at' => $found?->created_at?->toIso8601String(),
        ];
    }

    private function proposal(AnimDecodeProposal $p): array
    {
        return [
            'combination' => $p->combination,
            'player' => $p->player,
            'points' => $p->points,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function place(AnimDecodeActivity $activity): ?array
    {
        if ($activity->province) {
            return ['kind' => 'province', 'id' => $activity->province->id, 'name' => $activity->province->province_name];
        }

        return $activity->city
            ? ['kind' => 'city', 'id' => $activity->city->id, 'name' => $activity->city->city_name]
            : null;
    }
}
