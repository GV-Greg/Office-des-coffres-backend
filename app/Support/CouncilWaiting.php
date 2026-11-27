<?php

namespace App\Support;

use App\Models\CouncilMandate;
use App\Models\CouncilOfficePeriod;
use App\Models\Mandate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * L'attente du successeur (Greg, 27/11/2026, politique §6) : un conseiller dont le mandat est arrivé à sa fin enregistrée
 * garde les accès de son poste tant que l'Office ne connaît pas de successeur, jusqu'à `council_waiting_days` jours après le
 * TERME (`valid_until`) au plus — sauf l'écriture des prix, verrouillée au terme.
 *
 * 🔴 C'est une SOUPLESSE appliquée à la lecture des droits : RIEN n'est écrit. `holds_until` et la fin des périodes de poste
 * restent ce qu'ils sont ; l'historique, le profil et les exports disent la fin enregistrée.
 *
 * L'attente ne vaut que pour une fin NATURELLE (jamais après une passation enregistrée ou une révocation) et s'arrête dès que
 * l'Office connaît la relève :
 *  - un conseiller de la province est entré en fonction depuis le terme, ou un nouveau dirigeant y a reçu son poste ;
 *  - pour un poste précis, un autre mandat de la province l'a reçu depuis.
 */
final class CouncilWaiting
{
    /** @return array<int, int> mandats de conseil actuellement en attente de leur successeur */
    public static function mandateIds(): array
    {
        return self::waiting()->pluck('id')->all();
    }

    /** @return array<int, int> périodes de poste qui restent en vigueur le temps de cette attente */
    public static function periodIds(): array
    {
        return self::waiting()
            ->flatMap(fn (CouncilMandate $mandate) => $mandate->officePeriods
                ->filter(fn (CouncilOfficePeriod $period) => $period->end_reason === 'fin_du_mandat'
                    && $period->ended_at->equalTo($mandate->holds_until)
                    && ! self::officePassedOn($mandate, $period))
                ->pluck('id'))
            ->all();
    }

    /** Dernier instant possible de l'attente de ce mandat, s'il attend en ce moment ; sinon null. */
    public static function until(Mandate $mandate): ?Carbon
    {
        if (! $mandate instanceof CouncilMandate || ! in_array($mandate->id, self::mandateIds(), true)) {
            return null;
        }

        return self::deadline($mandate, (int) config('mandates.council_waiting_days'));
    }

    /** @return Collection<int, CouncilMandate> */
    private static function waiting(): Collection
    {
        $days = config('mandates.council_waiting_days');
        // Clé absente d'une configuration pas encore remise en cache : aucune souplesse plutôt qu'une durée inventée.
        if ($days === null) {
            return collect();
        }

        $now = Carbon::now();

        return CouncilMandate::query()
            ->where('status', Mandate::APPROVED)
            ->whereNotNull('in_office_from')
            ->where('in_office_from', '<=', $now)
            ->where('holds_until', '<=', $now)
            ->whereIn('holds_until_set_by', ['nominal', 'extension'])
            // Filtre large en base (les jours se comptent au calendrier du jeu, ci-dessous).
            ->where('valid_until', '>', $now->copy()->subDays((int) $days + 2))
            ->with('officePeriods.office')
            ->get()
            ->filter(fn (CouncilMandate $mandate) => self::deadline($mandate, (int) $days)->greaterThan($now) && ! self::relieved($mandate))
            ->values();
    }

    private static function deadline(CouncilMandate $mandate, int $days): Carbon
    {
        return Carbon::instance(MandateCalendar::addGameDays($mandate->valid_until, $days));
    }

    /** La relève du conseil est-elle connue : un entrant en fonction depuis le terme, ou un nouveau dirigeant ? */
    private static function relieved(CouncilMandate $mandate): bool
    {
        $now = Carbon::now();

        $newcomer = CouncilMandate::query()
            ->where('province_id', $mandate->province_id)
            ->whereKeyNot($mandate->id)
            ->whereIn('status', [Mandate::APPROVED, Mandate::REVOKED])
            ->where('in_office_from', '>=', $mandate->valid_until)
            ->where('in_office_from', '<=', $now)
            ->exists();

        return $newcomer || CouncilOfficePeriod::query()
            ->where('council_mandate_id', '!=', $mandate->id)
            ->where('started_at', '>=', $mandate->valid_until)
            ->where('started_at', '<=', $now)
            ->whereHas('office', fn ($office) => $office->where('key', 'leader'))
            ->whereHas('mandate', fn ($query) => $query->where('province_id', $mandate->province_id))
            ->exists();
    }

    /** Ce poste a-t-il été donné à un autre mandat de la province depuis que celui-ci le tient ? */
    private static function officePassedOn(CouncilMandate $mandate, CouncilOfficePeriod $period): bool
    {
        return CouncilOfficePeriod::query()
            ->where('council_mandate_id', '!=', $mandate->id)
            ->where('council_office_id', $period->council_office_id)
            ->where('started_at', '>', $period->started_at)
            ->where('started_at', '<=', Carbon::now())
            ->whereHas('mandate', fn ($query) => $query->where('province_id', $mandate->province_id))
            ->exists();
    }
}
