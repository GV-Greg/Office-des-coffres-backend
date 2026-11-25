<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\MineReport;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Illuminate\Support\Carbon;

/**
 * Lecture du Registre des mines (PR 4). Le serveur sert les FAITS — relevés déchiffrés, estampilles,
 * dates du mandat du lecteur — ; les analyses (état du parc, niveaux, bilans) se calculent côté site
 * avec le parseur qui a produit le relevé (modules/mineParser.js), sans redemander de collage.
 *
 * Deux règles portées ICI, parce que ce sont des règles et pas de l'affichage :
 *  - mi-mandat et fin de mandat = `in_office_from` DU LECTEUR + 30 / + 60 jours civils de Paris
 *    (R4 : « chacun le sien, bailli compris ») ;
 *  - prédécesseur = relevés d'AUTRES personnages dans les 30 jours avant ce début — des estampilles
 *    déjà écrites, jamais « qui occupait le poste » (contrainte 1 de la roadmap).
 * Le texte collé brut n'est pas servi : lourd, et inutile à la lecture (il sert à re-dériver).
 */
class MineRegistryReader
{
    public const PREDECESSOR_DAYS = 30;

    public const MID_DAYS = 30;

    public const END_DAYS = 60;

    public function __construct(private readonly MandateAuthority $authority) {}

    /** @return array<string, mixed>|null null : le personnage ne peut pas consulter le registre */
    public function readFor(Character $character): ?array
    {
        $mandate = $this->authority->mineReaderMandate($character);

        return $mandate ? $this->readForMandate($character, $mandate) : null;
    }

    /**
     * Le registre vu par UN mandat, en cours OU passé (bilans de mandat passés, 22/11/2026). Le mandat doit
     * appartenir au personnage ET lui avoir donné un poste de MINE_READER_OFFICES — en cours (le mandat de
     * `mineReaderMandate`) ou passé (`pastMineReaderMandates`) ; sinon null, jamais un autre mandat.
     *
     * 🔴 FENÊTRAGE d'un mandat PASSÉ : seuls les relevés de SA fenêtre sont servis — du début de la fenêtre « prédécesseur »
     * (`in_office_from` − 30 j) à la fin du mandat, `holds_until` EXCLU (le jour où le successeur entre en fonction n'est plus
     * le sien). Aucun relevé postérieur : sans cette borne, un ancien titulaire lirait pour toujours le travail de ses
     * successeurs. Un mandat EN COURS garde le comportement d'avant (aucune borne haute).
     *
     * @return array<string, mixed>|null
     */
    public function readForMandate(Character $character, CouncilMandate $mandate): ?array
    {
        if ($mandate->character_id !== $character->id || ! $mandate->province) {
            return null;
        }

        $current = $this->authority->mineReaderMandate($character);
        $isCurrent = $current !== null && $current->id === $mandate->id;
        $isPast = ! $isCurrent && $this->authority->pastMineReaderMandates($character)->contains('id', $mandate->id);
        if (! $isCurrent && ! $isPast) {
            return null;
        }

        $start = $mandate->in_office_from;
        $day = fn (?Carbon $instant) => $instant?->copy()->setTimezone(MandateCalendar::timezone())->toDateString();
        $windowFrom = $start ? $day(MandateCalendar::addGameDays($start, -self::PREDECESSOR_DAYS)) : null;
        $windowTo = $isPast ? $day($mandate->holds_until) : null;

        $reports = MineReport::query()
            ->with('character')
            ->where('province_id', $mandate->province_id)
            ->when($isPast && $windowFrom, fn ($query) => $query->whereDate('reported_at', '>=', $windowFrom))
            ->when($isPast && $windowTo, fn ($query) => $query->whereDate('reported_at', '<', $windowTo))
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get();

        return [
            'province' => ['id' => $mandate->province_id, 'name' => $mandate->province->province_name],
            'today' => MandateCalendar::today(),
            // Lecture seule d'un mandat TERMINÉ : l'écran le dit (« bilan du mandat terminé le … »), sans recalculer la règle.
            'read_only' => $isPast,
            'window' => $isPast ? ['from' => $windowFrom, 'to' => $windowTo] : null,
            'mandate' => $start ? [
                'id' => $mandate->id,
                'in_office_from' => $day($start),
                'mid_at' => $day(MandateCalendar::addGameDays($start, self::MID_DAYS)),
                'end_at' => $day(MandateCalendar::addGameDays($start, self::END_DAYS)),
                'ended_at' => $isPast ? $windowTo : null,
            ] : null,
            'predecessor' => $start ? $this->predecessor($reports, $character, $day($start), $windowFrom) : [],
            'reports' => $reports->map(fn (MineReport $r) => [
                'id' => $r->id,
                'reported_at' => $r->reported_at->toDateString(),
                'active' => (bool) $r->active,
                'replaced_by_id' => $r->replaced_by_id,
                'replaced_at' => $r->replaced_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
                'author' => $this->author($r),
                'report' => $r->payload['report'] ?? null,
                'prices' => $r->payload['prices'] ?? null,
                'rate' => $r->payload['rate'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * Relevés EN VIGUEUR écrits par d'autres que le lecteur dans les 30 jours avant son entrée en
     * fonction, regroupés par auteur et poste : « relevés de X, commissaire aux mines, du … au … ».
     *
     * @return array<int, array<string, mixed>>
     */
    private function predecessor($reports, Character $reader, string $start, string $from): array
    {
        return $reports
            ->filter(fn (MineReport $r) => $r->active
                && $r->character_id !== $reader->id
                && $r->reported_at->toDateString() >= $from
                && $r->reported_at->toDateString() < $start)
            ->groupBy(fn (MineReport $r) => ($r->character_id ?? 'supprime').'|'.$r->office_key)
            ->map(fn ($group) => $this->author($group->first()) + [
                'first' => $group->min(fn ($r) => $r->reported_at->toDateString()),
                'last' => $group->max(fn ($r) => $r->reported_at->toDateString()),
                'count' => $group->count(),
            ])
            ->sortBy('first')->values()->all();
    }

    /** @return array{pseudo: ?string, office_key: string, office_label: array{fr: string, en: string}} */
    private function author(MineReport $r): array
    {
        return [
            'pseudo' => $r->character?->pseudo,
            'office_key' => $r->office_key,
            'office_label' => ['fr' => MandateLabels::office($r->office_key, 'fr'), 'en' => MandateLabels::office($r->office_key, 'en')],
        ];
    }
}
