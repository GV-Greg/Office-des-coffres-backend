<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CouncilOffice;
use App\Services\OfficeHistory;
use App\Support\MandateApiErrors;
use App\Support\MandateLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Ma province » (fil admin/echanges/mandats-historique, 04 §11, 07 Q11) : l'historique des postes
 * de la province de RÉSIDENCE d'un personnage du joueur — résidence seule, décision de Greg.
 *
 * Même fonction de fusion que l'admin (OfficeHistory). Dates réelles uniquement (Q15) : le frontend
 * convertit. Libellés en FR et EN, comme le reste de l'API des mandats (Q11 du lot 2).
 *
 * N'expose pas : notes internes, refus, demandes en attente (Q4) — ni le fait qu'un compte a été
 * supprimé (`archived`) : rien ne le demande côté joueur, et ce serait une information sur un tiers.
 */
class ProvinceHistoryController extends Controller
{
    public function show(Request $request, int $character, OfficeHistory $history): JsonResponse
    {
        $character = $request->user()->characters()->with('city.province.kingdom')->find($character);
        if (! $character) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $province = $character->city?->province;
        if ($province === null) {
            // État vide prévu (04 §11) : résidence inconnue. Le frontend dit ce qui manque.
            return response()->json(['success' => true, 'province' => null]);
        }

        $entries = $history->forProvince($province->id);
        $byCity = $entries['mayors']->groupBy('city_id');
        $cities = $province->cities()->orderBy('city_name')->get();

        return response()->json([
            'success' => true,
            'province' => [
                'id' => $province->id,
                'name' => $province->province_name,
                'kingdom' => $province->kingdom?->kingdom_name,
            ],
            'offices' => CouncilOffice::query()->orderBy('position')->get()->map(fn (CouncilOffice $office) => [
                'key' => $office->key,
                'label' => self::labels(fn (string $l) => MandateLabels::office($office->key, $l)),
                'holders' => $entries['council']->where('office_key', $office->key)->values()->map(self::entry(...)),
            ]),
            'mayors' => $cities->filter(fn ($city) => $byCity->has($city->id))->values()->map(fn ($city) => [
                'city' => ['id' => $city->id, 'name' => $city->city_name],
                'holders' => $byCity->get($city->id)->values()->map(self::entry(...)),
            ]),
            // Q3 : ne jamais laisser croire la liste exhaustive.
            'cities_without_mandate' => $cities->reject(fn ($city) => $byCity->has($city->id))->pluck('city_name')->values(),
        ]);
    }

    private static function entry(array $entry): array
    {
        return [
            'pseudo' => $entry['pseudo'],
            'started_at' => $entry['started_at']->toIso8601String(),
            'ended_at' => $entry['ended_at']?->toIso8601String(),
            'ongoing' => $entry['ongoing'],
            'end_reason' => $entry['ongoing'] ? null : $entry['end_reason'],
            'end_reason_label' => $entry['ongoing'] ? null
                : self::labels(fn (string $l) => MandateLabels::reason($entry['end_reason'], $l)),
        ];
    }

    /** @return array{fr: string, en: string} */
    private static function labels(callable $label): array
    {
        return ['fr' => $label('fr'), 'en' => $label('en')];
    }
}
