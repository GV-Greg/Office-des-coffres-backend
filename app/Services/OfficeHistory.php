<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CouncilOfficePeriod;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Models\OfficeHistoryArchive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Historique des postes d'une province : qui a tenu quel titre du conseil, qui a été maire de quelle
 * ville, de quand à quand, et comment ça s'est fini (fil admin/echanges/mandats-historique).
 *
 * ⚠️ LA SEULE fonction de fusion des deux sources (exigence n°2 du 04) : les tables vivantes pour les
 * personnages qui existent, `office_history_archive` pour ceux qui ont été supprimés. Le Blade admin
 * et l'API joueur l'appellent toutes deux — ne jamais refaire la fusion ailleurs.
 *
 * Sans recouvrement par construction (12) : un personnage est vivant OU archivé. Et ce service est
 * aussi celui qui produit les lignes d'archive (`archiveCharacter`), avec les MÊMES normalisations :
 * une entrée vivante et la même entrée archivée sont identiques.
 *
 * Contenu (Q4) : les mandats validés (en cours, terminés, révoqués) et les périodes de poste ; ni
 * demande en attente, ni refus. Aucune note interne.
 */
class OfficeHistory
{
    /**
     * @return array{council: Collection<int, array>, mayors: Collection<int, array>}
     *                                                                                Entrées triées par début, normalisées par entry().
     */
    public function forProvince(int $provinceId): array
    {
        $live = $this->livePeriods(fn ($q) => $q->where('province_id', $provinceId))
            ->concat($this->liveMayors(fn ($q) => $q->whereHas('city', fn ($c) => $c->where('province_id', $provinceId))));

        $archived = OfficeHistoryArchive::query()->where('province_id', $provinceId)->get()
            ->map(fn (OfficeHistoryArchive $row) => $this->entry(
                level: $row->level, pseudo: $row->character_pseudo, provinceId: $row->province_id,
                provinceName: $row->province_name, cityId: $row->city_id, cityName: $row->city_name,
                officeKey: $row->office_key, startedAt: $row->started_at, endedAt: $row->ended_at,
                endReason: $row->end_reason, archived: true,
            ));

        // Tri TOTAL : des postes accordés à la même seconde (une passation, une réattribution) ont le
        // même début ; sans clés secondaires, leur ordre dépendrait de la source (vivante ou archivée).
        $all = $live->concat($archived)->sortBy([
            fn (array $a, array $b) => $a['started_at'] <=> $b['started_at'],
            fn (array $a, array $b) => ($a['office_key'] ?? '') <=> ($b['office_key'] ?? ''),
            fn (array $a, array $b) => ($a['city_name'] ?? '') <=> ($b['city_name'] ?? ''),
            fn (array $a, array $b) => $a['ended_at'] <=> $b['ended_at'],
            fn (array $a, array $b) => $a['pseudo'] <=> $b['pseudo'],
        ])->values();

        return [
            'council' => $all->where('level', 'council')->values(),
            'mayors' => $all->where('level', 'mayor')->values(),
        ];
    }

    /**
     * Copie l'historique d'un personnage dans l'archive, JUSTE AVANT sa suppression. Appelé par
     * AccountDeletion uniquement (porte unique, épinglée par AccountDeletionTest).
     */
    public function archiveCharacter(Character $character, ?Carbon $at = null): int
    {
        $at ??= Carbon::now();
        $entries = $this->livePeriods(fn ($q) => $q->where('character_id', $character->id))
            ->concat($this->liveMayors(fn ($q) => $q->where('character_id', $character->id)));

        foreach ($entries as $entry) {
            OfficeHistoryArchive::create([
                'level' => $entry['level'],
                'character_pseudo' => $entry['pseudo'],
                'province_id' => $entry['province_id'],
                'province_name' => $entry['province_name'],
                'city_id' => $entry['city_id'],
                'city_name' => $entry['city_name'],
                'office_key' => $entry['office_key'],
                'started_at' => $entry['started_at'],
                'ended_at' => $entry['ended_at'],
                'end_reason' => $entry['end_reason'],
                'archived_at' => $at,
            ]);
        }

        return $entries->count();
    }

    /** Périodes de poste du conseil, depuis les tables vivantes. */
    private function livePeriods(callable $scopeMandate): Collection
    {
        return CouncilOfficePeriod::query()
            ->whereHas('mandate', fn ($q) => $scopeMandate($q->whereIn('status', [Mandate::APPROVED, Mandate::REVOKED])))
            ->with(['office', 'mandate.character', 'mandate.province'])
            ->get()
            ->map(fn (CouncilOfficePeriod $p) => $this->entry(
                level: 'council', pseudo: (string) $p->mandate->character?->pseudo,
                provinceId: $p->mandate->province_id, provinceName: (string) $p->mandate->province?->province_name,
                cityId: null, cityName: null, officeKey: $p->office?->key,
                startedAt: $p->started_at, endedAt: $p->ended_at, endReason: $p->end_reason, archived: false,
            ));
    }

    /** Mandats de maire validés, depuis les tables vivantes. */
    private function liveMayors(callable $scope): Collection
    {
        return $scope(MayorMandate::query()->whereIn('status', [Mandate::APPROVED, Mandate::REVOKED]))
            ->with(['character', 'city.province'])
            ->get()
            ->map(fn (MayorMandate $m) => $this->entry(
                level: 'mayor', pseudo: (string) $m->character?->pseudo,
                provinceId: $m->city?->province_id, provinceName: (string) $m->city?->province?->province_name,
                cityId: $m->city_id, cityName: $m->city?->city_name, officeKey: null,
                startedAt: $m->in_office_from ?? $m->started_at, endedAt: $m->holds_until,
                // Révoqué : la cause est le motif de la révocation (« retranchement », « démission »…).
                endReason: $m->status === Mandate::REVOKED ? $m->decision_reason : 'fin_du_mandat',
                archived: false,
            ));
    }

    /** La forme unique d'une entrée, vivante ou archivée. */
    private function entry(
        string $level, string $pseudo, ?int $provinceId, string $provinceName, ?int $cityId, ?string $cityName,
        ?string $officeKey, Carbon $startedAt, ?Carbon $endedAt, ?string $endReason, bool $archived,
    ): array {
        return [
            'level' => $level,
            'pseudo' => $pseudo,
            'province_id' => $provinceId,
            'province_name' => $provinceName,
            'city_id' => $cityId,
            'city_name' => $cityName,
            'office_key' => $officeKey,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'end_reason' => $endReason,
            'ongoing' => $endedAt === null || $endedAt->isFuture(),
            'archived' => $archived,
        ];
    }
}
