<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Province;
use App\Models\ProvinceMinePrice;
use App\Services\MandateAuthority;
use App\Services\MinePrices;
use App\Support\MandateApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prix des mines par période (brief admin/content/brief-prix-registre.md). Ne valide que la FORME :
 * les règles vivent dans App\Services\MinePrices et MandateAuthority. L'acteur est le personnage du
 * compte qui occupe un poste dans la province de la route — les routes ne portent pas de personnage.
 */
class MinePriceController extends Controller
{
    public function __construct(
        private readonly MinePrices $prices,
        private readonly MandateAuthority $authority,
    ) {}

    /** Tous les jeux de prix de la province, du plus récent au plus ancien. */
    public function index(Request $request, Province $province): JsonResponse
    {
        if (! $this->reader($request, $province)) {
            return MandateApiErrors::error(403, 'mines.api.price_not_reader');
        }

        $editors = $this->editors($request, $province);

        return response()->json([
            'success' => true,
            'prices' => ProvinceMinePrice::query()->where('province_id', $province->id)
                ->with('createdBy', 'updatedBy', 'province')->orderByDesc('effective_from')->get()
                ->map(fn (ProvinceMinePrice $price) => $this->prices->present($price, $this->canEdit($editors, $price)))
                ->values(),
        ]);
    }

    /** Jeu en vigueur aujourd'hui, ou `null`. */
    public function current(Request $request, Province $province): JsonResponse
    {
        if (! $this->reader($request, $province)) {
            return MandateApiErrors::error(403, 'mines.api.price_not_reader');
        }

        $price = $this->prices->getCurrentPrice($province->id)?->load('createdBy', 'updatedBy', 'province');

        return response()->json([
            'success' => true,
            'price' => $price ? $this->prices->present($price, $this->canEdit($this->editors($request, $province), $price)) : null,
        ]);
    }

    public function store(Request $request, Province $province): JsonResponse
    {
        $actor = $this->manager($request, $province);
        if (! $actor) {
            return MandateApiErrors::error(403, 'mines.api.price_not_authorized');
        }

        $price = $this->prices->create($actor, $province, $this->validated($request));

        return response()->json(['success' => true, 'price' => $this->prices->present($price, true)], 201);
    }

    public function update(Request $request, Province $province, int $price): JsonResponse
    {
        $model = ProvinceMinePrice::query()->where('province_id', $province->id)->find($price);
        if (! $model) {
            return MandateApiErrors::error(404, 'mines.api.price_not_found');
        }

        $editors = $this->editors($request, $province);
        $actor = $editors->first(fn (Character $c) => $this->authority->canEditPrice($c, $model));
        if (! $actor) {
            // Un titulaire (actuel ou ancien) dont la date n'est plus couverte : « verrouillé ». Le dirigeant
            // et quiconque n'a jamais tenu ces postes : « non autorisé ».
            $held = $request->user()->characters->contains(fn (Character $c) => $this->authority->everHeldPriceOffice($c, $province));

            return MandateApiErrors::error(403, $held ? 'mines.api.price_locked' : 'mines.api.price_not_authorized', ['days' => config('mandates.price_edit_grace_days')]);
        }

        $updated = $this->prices->update($actor, $model, $this->validated($request, true));

        return response()->json(['success' => true, 'price' => $this->prices->present($updated, true)]);
    }

    /** @return array{effective_from: string, prices: array<string, numeric>, hourly_rate: numeric} */
    private function validated(Request $request, bool $update = false): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];

        return $request->validate([
            'effective_from' => [$update ? 'sometimes' : 'required', 'date_format:Y-m-d'],
            'prices' => ['required', 'array'],
            'prices.OR' => $money,
            'prices.FER' => $money,
            'prices.PIERRE' => $money,
            'prices.ARGILE' => $money,
            'prices.SEL' => $money,
            'hourly_rate' => $money,
        ]);
    }

    /** Personnage du compte qui peut CONSULTER les prix de cette province. */
    private function reader(Request $request, Province $province): ?Character
    {
        return $request->user()->characters->first(fn (Character $c) => $this->authority->canReadPrices($c)?->id === $province->id);
    }

    /** Personnage du compte qui peut ÉCRIRE un nouveau jeu dans cette province. */
    private function manager(Request $request, Province $province): ?Character
    {
        return $request->user()->characters->first(fn (Character $c) => $this->authority->canManagePrices($c)?->id === $province->id);
    }

    /** Personnages du compte susceptibles de corriger un prix de cette province. */
    private function editors(Request $request, Province $province)
    {
        return $request->user()->characters;
    }

    private function canEdit($characters, ProvinceMinePrice $price): bool
    {
        return $characters->contains(fn (Character $c) => $this->authority->canEditPrice($c, $price));
    }
}
