<?php

namespace App\Support;

use App\Models\CouncilOfficePeriod;
use Illuminate\Support\Carbon;

/**
 * Début d'une période de poste : la VRAIE date de prise de fonction, jamais la date de la validation du poste ou du mandat
 * (Greg, 16/10/2026 : « la validation au 08/10, c'est ma validation, elle ne doit pas rentrer en compte sur le poste
 * occupé par le personnage »). Un titre inscrit AVEC un mandat en cours est tenu depuis l'entrée en fonction du mandat.
 *
 * Une seule réserve, l'EXCLUSIVITÉ du poste dans la province (MandateOfficeTest) : on ne remonte pas le début d'une
 * période sur un intervalle où un autre mandat a déjà tenu ce poste — le poste ne peut pas avoir deux titulaires à la
 * fois. Dans ce cas, le début reste la date de la validation (« maintenant »).
 */
class OfficeStart
{
    /** Le poste est-il libre de tout autre titulaire de `$from` (inclus) à `$until` (exclu) dans la province ? */
    public static function isFree(int $officeId, int $provinceId, int $exceptMandateId, Carbon $from, Carbon $until): bool
    {
        return ! CouncilOfficePeriod::query()
            ->where('council_office_id', $officeId)
            ->where('council_mandate_id', '!=', $exceptMandateId)
            ->where('started_at', '<', $until)
            ->where('ended_at', '>', $from)
            ->whereHas('mandate', fn ($mandate) => $mandate->where('province_id', $provinceId))
            ->exists();
    }
}
