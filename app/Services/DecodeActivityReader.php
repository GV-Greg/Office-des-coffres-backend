<?php

namespace App\Services;

use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;

/**
 * Lecture de « Décode le code » : la page publique ET l'écran de l'organisateur, une seule forme, deux sources.
 *
 *  - Activité vivante, privée ou sans compte : les tables (coffres, propositions).
 *  - Activité SANS COMPTE close : la PHOTO FIGÉE (`closing_snapshot`, D7, D18) — le reste a été effacé à la clôture.
 *
 * 🔴 Le code du coffre EN COURS n'en sort que pour l'organisateur (`$forOwner`, tour 03, Q3) : la page publique ne
 * montre un code qu'une fois le coffre percé, avec son découvreur. Gardé par DecodeActivityApiTest dans les deux sens.
 * 🔴 Le pseudo d'un organisateur PRIVÉ se lit EN DIRECT sur son personnage : jamais recopié (la suppression du compte
 * emporte l'activité, D21, mais une copie en texte survivrait à toute autre voie).
 * ⚠️ Vocabulaire (D34) : `character_name` est le nom du PERSONNAGE qui a proposé.
 */
class DecodeActivityReader
{
    public function read(AnimDecodeActivity $activity, bool $forOwner = false): array
    {
        $activity->loadMissing(['character', 'kingdom', 'province', 'city', 'type']);

        if ($activity->isClosed() && $activity->is_anonymous && $activity->closing_snapshot) {
            return $this->fromSnapshot($activity);
        }

        $secrets = $activity->secrets()->with('proposals')->get();
        $current = $secrets->first(fn (AnimDecodeSecret $s) => ! $s->status);

        $data = $this->base($activity) + [
            'current' => $current ? $this->current($current, $secrets->search($current) + 1, $forOwner) : null,
            'opened' => $this->opened($secrets),
            'ranking' => $this->ranking($secrets),
        ];

        // L'organisateur voit en plus ce que le site lui permet : rouvrir (privé seulement) et l'état de son adresse.
        if ($forOwner) {
            $data['can_reopen'] = ! $activity->is_anonymous && $activity->isClosed();
            if ($activity->is_anonymous) {
                $data['email'] = ['set' => $activity->organizer_email !== null, 'verified' => $activity->hasVerifiedEmail()];
            }
        }

        return $data;
    }

    /**
     * La photo figée écrite à la clôture d'une activité sans compte (D18) : nom, organisateur, lieu, résultats, classement
     * final. Ni les propositions, ni le coffre non percé.
     */
    public function snapshot(AnimDecodeActivity $activity): array
    {
        $activity->loadMissing(['kingdom', 'province', 'city']);
        $secrets = $activity->secrets()->with('proposals')->get();

        return [
            'name' => $activity->name,
            'organizer' => $this->organizer($activity),
            'place' => $this->place($activity),
            'opened' => $this->opened($secrets),
            'ranking' => $this->ranking($secrets),
        ];
    }

    /**
     * Le compte rendu COMPLET envoyé à l'organisateur à la clôture (D31) : tous les coffres, codes compris, et toutes les
     * propositions — la seule copie qui lui en reste.
     *
     * @return array{name: string, slug: string, chests: list<array<string, mixed>>, ranking: list<array<string, mixed>>}
     */
    public function fullReport(AnimDecodeActivity $activity): array
    {
        $secrets = $activity->secrets()->with('proposals')->get();
        $chests = [];
        foreach ($secrets as $i => $secret) {
            $found = $secret->proposals->first(fn (AnimDecodeProposal $p) => $p->isFound());
            $chests[] = [
                'number' => $i + 1,
                'code' => $secret->code,
                'opened' => (bool) $secret->status,
                'discoverer' => $found?->character_name,
                'proposals' => $secret->proposals->map(fn (AnimDecodeProposal $p) => [
                    'combination' => $p->combination,
                    'character_name' => $p->character_name,
                    'points' => $p->points,
                ])->all(),
            ];
        }

        return ['name' => $activity->name, 'slug' => $activity->slug, 'chests' => $chests, 'ranking' => $this->ranking($secrets)];
    }

    private function base(AnimDecodeActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'slug' => $activity->slug,
            'name' => $activity->name,
            'type' => $activity->type->key,
            'status' => $activity->status,
            'mode' => $activity->is_anonymous ? 'anonymous' : 'private',
            'organizer' => $this->organizer($activity),
            'place' => $this->place($activity),
            'launched_at' => $activity->launched_at?->toIso8601String(),
            'closed_at' => $activity->closed_at?->toIso8601String(),
        ];
    }

    private function fromSnapshot(AnimDecodeActivity $activity): array
    {
        $snapshot = $activity->closing_snapshot;

        return [
            'id' => $activity->id,
            'slug' => $activity->slug,
            'name' => $snapshot['name'] ?? $activity->name,
            'type' => $activity->type->key,
            'status' => $activity->status,
            'mode' => 'anonymous',
            'organizer' => $snapshot['organizer'] ?? null,
            'place' => $snapshot['place'] ?? null,
            'launched_at' => $activity->launched_at?->toIso8601String(),
            'closed_at' => $activity->closed_at?->toIso8601String(),
            'current' => null,
            'opened' => $snapshot['opened'] ?? [],
            'ranking' => $snapshot['ranking'] ?? [],
        ];
    }

    private function organizer(AnimDecodeActivity $activity): ?string
    {
        return $activity->is_anonymous ? $activity->organizer_name : $activity->character?->pseudo;
    }

    /** Classement cumulé sur tous les coffres : points par personnage, du plus haut au plus bas. */
    private function ranking($secrets): array
    {
        return $secrets->flatMap->proposals
            ->groupBy('character_name')
            ->map(fn ($proposals, $name) => ['character_name' => $name, 'points' => $proposals->sum('points')])
            ->sort(fn ($a, $b) => [$b['points'], $a['character_name']] <=> [$a['points'], $b['character_name']])
            ->values()
            ->all();
    }

    private function opened($secrets): array
    {
        $opened = [];
        foreach ($secrets as $i => $secret) {
            if ($secret->status) {
                $found = $secret->proposals->first(fn (AnimDecodeProposal $p) => $p->isFound());
                $opened[] = [
                    'number' => $i + 1,
                    'code' => $secret->code,
                    'discoverer' => $found?->character_name,
                    'found_at' => $found?->created_at?->toIso8601String(),
                ];
            }
        }

        return $opened;
    }

    private function current(AnimDecodeSecret $secret, int $number, bool $forOwner): array
    {
        $data = [
            'number' => $number,
            'proposals' => $secret->proposals->map(fn (AnimDecodeProposal $p) => [
                'combination' => $p->combination,
                'character_name' => $p->character_name,
                'points' => $p->points,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->all(),
        ];

        // L'organisateur seul : id (pour saisir une proposition) et code (pour vérifier).
        return $forOwner ? ['id' => $secret->id, 'code' => $secret->code] + $data : $data;
    }

    private function place(AnimDecodeActivity $activity): ?array
    {
        if ($activity->kingdom) {
            return ['kind' => 'kingdom', 'id' => $activity->kingdom->id, 'name' => $activity->kingdom->kingdom_name];
        }
        if ($activity->province) {
            return ['kind' => 'province', 'id' => $activity->province->id, 'name' => $activity->province->province_name];
        }

        return $activity->city
            ? ['kind' => 'city', 'id' => $activity->city->id, 'name' => $activity->city->city_name]
            : null;
    }
}
