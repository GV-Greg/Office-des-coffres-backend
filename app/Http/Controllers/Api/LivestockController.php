<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\LivestockBirth;
use App\Models\Province;
use App\Models\ProvinceFeedPrice;
use App\Services\FeedPrices;
use App\Services\LivestockRegister;
use App\Services\MandateAuthority;
use App\Support\Livestock;
use App\Support\MandateApiErrors;
use App\Support\MandateCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Livre des naissances. Tout passe par un PERSONNAGE du compte : la province vient de son poste, jamais de la requête. Ne
 * valide que la forme ; les règles vivent dans MandateAuthority, LivestockRegister et FeedPrices.
 */
class LivestockController extends Controller
{
    public function __construct(
        private readonly MandateAuthority $authority,
        private readonly LivestockRegister $register,
        private readonly FeedPrices $prices,
    ) {}

    /** Ce que le personnage peut faire maintenant, avec les données de sa province. `province` null : aucun poste. */
    public function show(Request $request, int $character): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }

        $reader = $this->authority->livestockReaderMandate($character);
        $writer = $this->authority->birthWriterMandate($character);
        $province = $reader?->province;
        $pricer = $this->authority->canManageFeedPrices($character) !== null;
        $day = fn ($instant) => $instant ? MandateCalendar::gameDayOf($instant) : null;

        return response()->json([
            'success' => true,
            'province' => $province ? ['id' => $province->id, 'name' => $province->province_name] : null,
            'can_record' => $writer !== null,
            'can_configure' => $reader !== null,
            'can_price' => $pricer,
            'today' => MandateCalendar::today(),
            // Premier jour pour lequel une naissance peut s'inscrire : l'entrée en fonction.
            'first_day' => $day($writer?->in_office_from),
            // null : rien n'a été réglé ; [] : aucune espèce élevée.
            'species' => $province ? $this->register->species($province->id) : null,
            'births' => $province ? $this->register->births($province->id, writer: $writer ? $character : null) : [],
            'prices' => $province ? $this->prices->all($province, $pricer ? $character : null) : [],
            'past_mandates' => $this->authority->pastLivestockReaderMandates($character)->map(fn (CouncilMandate $mandate) => [
                'id' => $mandate->id,
                'province' => ['id' => $mandate->province_id, 'name' => $mandate->province?->province_name],
                'in_office_from' => $day($mandate->in_office_from),
                'holds_until' => $day($mandate->holds_until),
            ])->values(),
        ]);
    }

    /** Un mandat passé, en lecture seule, fenêtré. Mandat d'un autre ou sans droit : 403 (jamais 404). */
    public function mandate(Request $request, int $character, int $mandate): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }

        $past = $this->authority->pastLivestockReaderMandates($character)->firstWhere('id', $mandate);
        if (! $past) {
            return MandateApiErrors::error(403, 'livestock.api.not_reader');
        }

        [$from, $to] = $this->register->pastWindow($character, $past);

        return response()->json([
            'success' => true,
            'province' => ['id' => $past->province_id, 'name' => $past->province->province_name],
            'window' => ['from' => $from, 'to' => $to],
            'births' => $this->register->births($past->province_id, $from, $to),
            'prices' => $this->prices->all($past->province, to: $to),
        ]);
    }

    public function storeBirth(Request $request, int $character): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }
        $mandate = $this->authority->birthWriterMandate($character);
        if (! $mandate) {
            return MandateApiErrors::error(403, 'livestock.api.not_recorder');
        }

        $data = $request->validate([
            'born_on' => ['required', 'date_format:Y-m-d'],
            'species' => ['required', Rule::in(array_keys(Livestock::SPECIES))],
            'ingredient' => ['required', Rule::in(Livestock::FEEDS)],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Livestock::MAX_BATCH],
            'taken' => ['required', 'integer', 'min:1', 'max:1000'],
            'wasted' => ['required', 'numeric', 'min:0', 'lt:1'],
        ]);

        $birth = $this->register->record($character, $mandate, $data);

        return response()->json(['success' => true, 'birth' => $this->register->present($birth, $character)], 201);
    }

    public function withdrawBirth(Request $request, int $character, int $birth): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }
        $mandate = $this->authority->birthWriterMandate($character);
        $model = $mandate ? LivestockBirth::query()->where('province_id', $mandate->province_id)->find($birth) : null;
        if (! $model) {
            return MandateApiErrors::error(403, 'livestock.api.not_recorder');
        }

        $this->register->withdraw($character, $model);

        return response()->json(['success' => true]);
    }

    public function updateSpecies(Request $request, int $character): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }
        $province = $this->authority->livestockReaderMandate($character)?->province;
        if (! $province) {
            return MandateApiErrors::error(403, 'livestock.api.not_configurer');
        }

        $data = $request->validate([
            'species' => ['present', 'array', 'max:'.count(Livestock::SPECIES)],
            'species.*' => [Rule::in(array_keys(Livestock::SPECIES))],
        ]);

        return response()->json(['success' => true, 'species' => $this->register->setSpecies($province, $data['species'])]);
    }

    public function storePrice(Request $request, int $character): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }
        $province = $this->authority->canManageFeedPrices($character);
        if (! $province) {
            return $this->refusePrice($character);
        }

        $price = $this->prices->create($character, $province, $this->validatedPrice($request));

        return response()->json(['success' => true, 'price' => $this->prices->present($price, true)], 201);
    }

    public function updatePrice(Request $request, int $character, int $price): JsonResponse
    {
        $character = $this->own($request, $character);
        if (! $character) {
            return $this->unknownCharacter();
        }
        $province = $this->authority->canManageFeedPrices($character);
        $model = $province ? ProvinceFeedPrice::query()->where('province_id', $province->id)->find($price) : null;
        if (! $model) {
            return $this->refusePrice($character);
        }

        $updated = $this->prices->update($character, $province, $model, $this->validatedPrice($request));

        return response()->json(['success' => true, 'price' => $this->prices->present($updated, true)]);
    }

    /** @return array{effective_from: string, prices: array<string, numeric|null>} */
    private function validatedPrice(Request $request): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
        $rules = ['effective_from' => ['required', 'date_format:Y-m-d'], 'prices' => ['required', 'array']];
        foreach (Livestock::FEEDS as $feed) {
            // Au moins un prix par jeu : chaque nourriture est facultative tant qu'une autre est donnée.
            $others = collect(Livestock::FEEDS)->reject(fn ($other) => $other === $feed)->map(fn ($other) => 'prices.'.$other)->implode(',');
            $rules['prices.'.$feed] = ['required_without_all:'.$others, ...$money];
        }

        return $request->validate($rules);
    }

    /** Un bailli (actuel, en attente ou ancien) : « verrouillé » ; quiconque d'autre : « non autorisé ». */
    private function refusePrice(Character $character): JsonResponse
    {
        $held = $this->authority->everHeldFeedPriceOffice($character);

        return MandateApiErrors::error(403, $held ? 'livestock.api.price_locked' : 'livestock.api.price_not_authorized');
    }

    private function own(Request $request, int $character): ?Character
    {
        return $request->user()->characters()->find($character);
    }

    private function unknownCharacter(): JsonResponse
    {
        return MandateApiErrors::error(404, 'mandates.api.character_not_found');
    }
}
