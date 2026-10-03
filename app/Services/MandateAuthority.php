<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\MayorMandate;
use Illuminate\Support\Carbon;

/**
 * SEUL point d'entrée des modules pour savoir qui a le droit d'écrire au titre d'un poste
 * (admin/strategies/donnees-utilisateur.md §5quinquies). Ne répond que « maintenant » : pas de
 * paramètre de date (Q10).
 *
 * ⚠️ DEUX LIMITES À NE PAS « RÉPARER » (tour 05 du fil admin/echanges/mandats-lot1) :
 *
 * 1. Une province SANS AUCUNE autorité de poste est un état LÉGITIME. À chaque élection d'un
 *    dirigeant, tous les postes sont vidés jusqu'à ses nominations — un à deux jours tous les
 *    60 jours, et plus si le dirigeant démissionne. Un repli qui donnerait l'autorité à tous les
 *    conseillers pendant cette fenêtre serait un TROU DE SÉCURITÉ.
 *
 * 2. Une réponse négative veut dire « l'Office ne connaît pas de Juge », PAS « il n'y a pas de
 *    Juge ». L'Office ne connaît que les mandats déclarés par les joueurs (2 ou 3 conseillers sur
 *    12). Un module ne doit jamais conclure à un siège vacant depuis une absence d'information.
 */
class MandateAuthority
{
    public function activeMayorMandate(Character $character): ?MayorMandate
    {
        return MayorMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->first();
    }

    /** Appartenance au conseil, quel que soit le poste. N'autorise rien à elle seule (§5quinquies). */
    public function activeCouncilMandate(Character $character): ?CouncilMandate
    {
        return CouncilMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->first();
    }

    /**
     * Le personnage détient-il MAINTENANT ce poste titré ? Clé obligatoire (Q9) : l'autorisation se
     * vérifie par titre, jamais par appartenance. Le poste détenu est la période en vigueur de
     * council_office_periods (Q17) — sur un mandat lui-même en fonction.
     */
    public function holdsCouncilOffice(Character $character, string $officeKey): bool
    {
        $now = Carbon::now();

        return CouncilMandate::query()->inOfficeNow()
            ->where('character_id', $character->id)
            ->whereHas('officePeriods', fn ($periods) => $periods
                ->where('started_at', '<=', $now)
                ->where('ended_at', '>', $now)
                ->whereHas('office', fn ($office) => $office->where('key', $officeKey)))
            ->exists();
    }
}
