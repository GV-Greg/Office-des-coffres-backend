<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\CustomsReport;
use App\Support\MandateCalendar;
use Illuminate\Support\Collection;

/**
 * Vue d'ensemble de la province (module Sécurité, PR 5 ; fil admin/echanges/module-securite 18 et 19, maquette validée par Greg le 23/11/2026).
 * LECTURE SEULE, **aucun droit nouveau** : la vue ne montre que ce que le rôle lit déjà ville par ville (`CustomsAccess::readableCities`).
 *
 * Fusion des DERNIÈRES inscriptions de chaque ville, **chaque ligne datée de son jour de bilan**, **jamais de différentiel entre villes** (décision de
 * Greg, 21/11/2026) : une ville a pu envoyer le guet hier et les armées il y a quatre jours, chaque ligne dit donc le jour du bilan le plus récent QUI
 * CONTIENT sa section. Aucune limite d'âge (Q2 : la vue ne cache rien, elle montre que c'est vieux). On ne fusionne PAS les listes d'habitants :
 * seulement ce qui compte pour la sécurité — marques des listes, groupes à surveiller, armées — et, par ville, la couverture et les arrivées et départs.
 *
 * Agrégé côté SERVEUR (minimisation) : jamais les listes complètes d'habitants de toutes les villes dans le navigateur, jamais le brut, jamais l'auteur.
 * La coloration vient de `CustomsReports::present`, à la date de CHAQUE bilan (comme dans le Registre).
 */
class CustomsProvinceOverview
{
    /** Au plus ce nombre de bilans lus par ville pour retrouver chaque section (le plus récent qui la contient) : borne le coût du déchiffrement. */
    private const LOOKBACK = 60;

    private const SECTIONS = ['guet', 'visions', 'armies_groups'];

    public function __construct(
        private readonly CustomsAccess $access,
        private readonly CustomsReports $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $access  CustomsAccess::for()
     * @return array<string, mixed>
     */
    public function build(Character $viewer, array $access, ?string $asOf = null): array
    {
        // « Situation au jour D » (Greg, 24/11/2026 : « naviguer dans les jours ») : pour chaque ville, ses bilans JUSQU'À ce jour ; jamais le futur.
        $today = MandateCalendar::today();
        $asOf = ($asOf === null || $asOf > $today) ? $today : $asOf;
        $readable = $this->access->readableCities($access);
        $cities = [];
        $marks = [];
        $groups = [];
        $armies = [];

        foreach ($readable as $city) {
            $latest = $this->latestSections($city, $viewer, $access, $asOf);
            $where = ['id' => $city->id, 'name' => $city->city_name];

            $guet = $latest['guet'] ?? null;
            $block = $latest['armies_groups'] ?? null;
            $cities[] = [
                'id' => $city->id,
                'name' => $city->city_name,
                'last_report_day' => $latest['last_day'],
                'guet_day' => $guet['day'] ?? null,
                'inhabitants' => $guet['section']['read_total'] ?? null,
                // La liste des habitants (pseudo, noblesse, couleur) — Greg, 24/11/2026 : « la liste des habitants aussi » (dans un accordéon fermé). Les mêmes
                // lecteurs la lisent déjà ville par ville dans le Registre ; jamais le brut, la réputation ni l'auteur.
                'villagers' => isset($guet['section']['villagers']) ? array_map(
                    fn (array $v) => ['pseudo' => $v['pseudo'], 'noblesse' => $v['noblesse'] ?? '', 'colour' => $v['colour'] ?? null],
                    $guet['section']['villagers'],
                ) : null,
                // Le NOMBRE de groupes du bilan (jamais leur détail) : on voit qu'un bilan existe même quand rien n'est à surveiller.
                'groups' => isset($block['section']) ? count($block['section']['groups'] ?? []) : null,
                // Les PSEUDOS des arrivées et des départs (Greg, 24/11/2026 : « pas juste les comptes »), comparés au bilan antérieur de la MÊME ville ; ce sont
                // des différences, pas la liste des habitants. `null` : aucun bilan antérieur pour comparer.
                'arrivals' => $guet['section']['diff']['arrivals'] ?? null,
                'departures' => $guet['section']['diff']['departures'] ?? null,
                'compared_to' => $guet['section']['diff']['compared_to'] ?? null,
            ];

            foreach ($guet['section']['villagers'] ?? [] as $villager) {
                $this->mark($marks, $villager, 'villager', $where, $guet['day']);
            }

            $visions = $latest['visions'] ?? null;
            foreach ($visions['section']['entries'] ?? [] as $entry) {
                foreach ($entry['singles'] ?? [] as $single) {
                    $this->mark($marks, $single, 'vision', $where, $visions['day']);
                }
                foreach ($entry['groups'] ?? [] as $group) {
                    foreach ($group as $member) {
                        $this->mark($marks, $member, 'vision', $where, $visions['day']);
                    }
                }
            }

            foreach ($block['section']['armies'] ?? [] as $army) {
                $this->mark($marks, $army['commander'], 'army_commander', $where, $block['day']);
                $armies[] = ['name' => $army['name'], 'commander' => $army['commander'], 'city' => $where, 'day' => $block['day']];
            }
            foreach ($block['section']['groups'] ?? [] as $group) {
                foreach ($group['members'] as $member) {
                    $this->mark($marks, $member, 'group_member', $where, $block['day']);
                }
                // TOUS les groupes (Greg, 24/11/2026), chacun avec son indicateur : seul « Groupe de … » alerte (« groupe à surveiller », jamais « brigand », P8).
                // Le détail d'un groupe (« Fermé »…) n'est ni gardé ni servi.
                $groups[] = ['type' => $group['type'], 'alert' => (bool) $group['alert'], 'members' => $group['members'], 'city' => $where, 'day' => $block['day']];
            }
        }

        // Rouge avant vert, puis le plus récent, puis la ville et le pseudo : l'ordre est stable.
        usort($marks, fn (array $a, array $b) => [$a['colour'] === 'red' ? 0 : 1, $b['day'], $a['city']['name'], mb_strtolower($a['pseudo'])]
            <=> [$b['colour'] === 'red' ? 0 : 1, $a['day'], $b['city']['name'], mb_strtolower($b['pseudo'])]);
        usort($groups, fn (array $a, array $b) => [$b['day'], $a['city']['name']] <=> [$a['day'], $b['city']['name']]);
        usort($armies, fn (array $a, array $b) => [$b['day'], $a['city']['name'], $a['name']] <=> [$a['day'], $b['city']['name'], $b['name']]);

        return [
            'province' => ['id' => $access['province']->id, 'name' => $access['province']->province_name],
            'today' => $today,
            'as_of' => $asOf,
            // Les jours qui ont un bilan dans l'une des villes lisibles (les 90 plus récents) : de quoi naviguer, comme dans le Registre.
            'days' => $this->days($readable),
            'cities' => $cities,
            'marks' => $marks,
            'groups' => $groups,
            'armies' => $armies,
        ];
    }

    /**
     * Pour une ville : le jour de son dernier bilan (toutes sections), et, par section, le bilan le PLUS RÉCENT qui la contient (déjà présentée :
     * couleurs à la date de ce bilan, différentiel du guet).
     *
     * @return array<string, mixed>
     */
    private function latestSections(City $city, Character $viewer, array $access, string $asOf): array
    {
        $found = ['last_day' => null];

        $rows = CustomsReport::query()->where('city_id', $city->id)->where('active', true)->whereDate('reported_on', '<=', $asOf)->orderByDesc('reported_on')->limit(self::LOOKBACK)->get();
        foreach ($rows as $report) {
            $found['last_day'] ??= $report->reported_on->toDateString();
            $missing = array_values(array_filter(
                self::SECTIONS,
                fn (string $key) => ! isset($found[$key]) && isset(($report->payload['sections'] ?? [])[$key]),
            ));
            if ($missing === []) {
                continue;
            }

            $presented = $this->reports->present($report, $viewer, $access)['sections'];
            foreach ($missing as $key) {
                if (isset($presented[$key])) {
                    $found[$key] = ['day' => $report->reported_on->toDateString(), 'section' => $presented[$key]];
                }
            }
            if (count(array_intersect(self::SECTIONS, array_keys($found))) === count(self::SECTIONS)) {
                break;
            }
        }

        return $found;
    }

    /**
     * Jours du jeu qui ont un bilan en vigueur dans l'une des villes lisibles, du plus récent au plus ancien (90 au plus).
     *
     * @param  Collection<int, City>  $cities
     * @return array<int, string>
     */
    private function days($cities): array
    {
        return CustomsReport::query()->whereIn('city_id', $cities->pluck('id'))->where('active', true)->select('reported_on')->distinct()
            ->orderByDesc('reported_on')->limit(90)->pluck('reported_on')->map(fn ($day) => $day->toDateString())->all();
    }

    /**
     * Ajoute une ligne « marque » : un pseudo qui figure sur une liste (rouge ou verte) à la date de ce bilan, où et comment il a été vu. Un pseudo
     * vu deux fois au même endroit le même jour ne compte qu'une fois ; vu dans deux villes ou deux jours, il apparaît deux fois (jamais un « déplacement » :
     * ce serait un différentiel entre villes).
     *
     * @param  array<int, array<string, mixed>>  $marks
     * @param  array{pseudo: string, colour?: ?string}  $entry
     * @param  array{id: int, name: string}  $city
     */
    private function mark(array &$marks, array $entry, string $as, array $city, string $day): void
    {
        if (empty($entry['colour'])) {
            return;
        }

        $key = mb_strtolower($entry['pseudo']).'|'.$as.'|'.$city['id'].'|'.$day;
        $marks[$key] = ['pseudo' => $entry['pseudo'], 'colour' => $entry['colour'], 'as' => $as, 'city' => $city, 'day' => $day];
    }
}
