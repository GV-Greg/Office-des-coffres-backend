<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\MineReport;
use App\Models\Province;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Écriture du Registre des mines (PR 1b ; brief admin/content/brief-registre-mines.md §3, §6 ; fil
 * admin/echanges/registre-mines, R3 et R3 bis). TOUTES les règles d'écriture vivent ici, le
 * contrôleur ne valide que la forme.
 *
 * - Autorisation : `MandateAuthority::canManageMines()` — la province est celle du POSTE, jamais la
 *   résidence ni le collage. Le dirigeant consulte mais n'écrit pas (canReadMines).
 * - Estampille : le poste tenu AU MOMENT de l'écriture (contrainte 1 de la roadmap).
 * - Date du relevé : le jour du collage, à Paris, calculé ici — jamais fourni par le client.
 * - Ajout seul (§3) : un relevé ne se supprime jamais. Même province et même jour → REMPLACEMENT,
 *   après confirmation explicite, l'ancien restant visible (`active` NULL, `replaced_by_id`).
 * - R3 bis (Greg, 05/10) : remplacement REFUSÉ si le relevé analysé est identique, ou s'il en dit
 *   strictement moins. Comparaison sur le relevé ANALYSÉ, jamais sur le texte collé : deux collages
 *   qui diffèrent d'un espace mais analysent pareil sont identiques.
 */
class MineRegistry
{
    public function __construct(private readonly MandateAuthority $authority) {}

    /**
     * @param  array{raw: string, report: array, prices?: array|null, rate?: float|int|null}  $data
     * @return array{report: MineReport, replaced: ?MineReport}
     *
     * @throws MandateRefusal refus d'autorisation, relevé identique, relevé qui en dit moins
     * @throws MineReplacementNeedsConfirmation un relevé du jour existe et `$confirmReplace` est faux
     */
    public function record(Character $character, array $data, bool $confirmReplace): array
    {
        $province = $this->authority->canManageMines($character);
        if (! $province) {
            throw MandateRefusal::make('character', 'mines.api.mine_not_authorized');
        }

        $officeKey = $this->authority->holdsCouncilOffice($character, 'commissaire_mines') ? 'commissaire_mines' : 'bailli';
        $today = Carbon::now('Europe/Paris')->toDateString();

        return DB::transaction(function () use ($character, $data, $confirmReplace, $province, $officeKey, $today) {
            $existing = MineReport::query()
                ->where('province_id', $province->id)
                ->whereDate('reported_at', $today)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertReplaceable($existing, $data['report'], $province);
                if (! $confirmReplace) {
                    throw new MineReplacementNeedsConfirmation($existing, $province);
                }
                $existing->update(['active' => null]);
            }

            $report = MineReport::create([
                'province_id' => $province->id,
                'reported_at' => $today,
                'character_id' => $character->id,
                'office_key' => $officeKey,
                'payload' => [
                    'raw' => $data['raw'],
                    'report' => $data['report'],
                    'prices' => $data['prices'] ?? null,
                    'rate' => $data['rate'] ?? null,
                ],
            ]);

            $existing?->update(['replaced_by_id' => $report->id, 'replaced_at' => Carbon::now()]);

            return ['report' => $report, 'replaced' => $existing];
        });
    }

    /** R3 bis : identique → refus ; strictement moins → refus ; tout le reste → remplaçable. */
    private function assertReplaceable(MineReport $existing, array $incoming, Province $province): void
    {
        $old = self::facts($existing->payload['report'] ?? []);
        $new = self::facts($incoming);
        $params = ['province' => $province->province_name];

        if ($new == $old) {
            throw MandateRefusal::make('report', 'mines.api.mine_report_identical', $params);
        }
        if (count($new) < count($old) && array_intersect_assoc($new, $old) == $new) {
            throw MandateRefusal::make('report', 'mines.api.mine_report_less', $params);
        }
    }

    /**
     * Le relevé analysé, aplati en faits « chemin → valeur » indépendants de l'ordre et de la langue :
     * les mines sont clavetées sur leur NŒUD (numéro en repli, comme mineKey côté frontend), les
     * libellés (« Mine d'or » / « Gold mine ») n'entrent pas dans la comparaison, les nombres sont
     * normalisés (25 = 25.0 = « 25 »).
     *
     * @return array<string, string>
     */
    public static function facts(array $report): array
    {
        $facts = [];
        $key = fn (array $mine) => ! empty($mine['noeud']) ? 'noeud:'.$mine['noeud'] : 'numero:'.($mine['number'] ?? '?');
        $value = fn ($v) => is_numeric($v) ? (string) (0 + $v) : trim((string) $v);

        foreach ($report['mines'] ?? [] as $mine) {
            foreach ($mine['days'] ?? [] as $day => $fields) {
                foreach ((array) $fields as $field => $v) {
                    if ($v !== null) {
                        $facts["mine|{$key($mine)}|{$day}|{$field}"] = $value($v);
                    }
                }
            }
        }
        foreach ($report['states'] ?? [] as $state) {
            foreach ($state as $field => $v) {
                if ($v !== null && ! in_array($field, ['number', 'noeud', 'label', 'resource'], true)) {
                    $facts["state|{$key($state)}|{$field}"] = $value($v);
                }
            }
        }
        ksort($facts);

        return $facts;
    }
}
