<?php

namespace App\Http\Controllers\Api\Anim;

use App\Exceptions\MandateRefusal;
use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Services\RewardsGridReader;
use App\Services\RewardsGridWorkflow;
use App\Support\AnimApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grille des lots — API du mode PRIVÉ (lié à un personnage, `auth:api`) et lecture publique des deux modes. Ne valide
 * que la FORME : toute règle (propriété, dimensions, slug, une grille active par type…) vit dans RewardsGridWorkflow.
 * Le mode sans compte a son propre contrôleur (RewardsAnonymousController).
 * ⚠️ Routes ajoutées : route:cache en prod.
 */
class RewardsGridController extends Controller
{
    /**
     * Forme d'un ajout de lot, commune aux deux modes : UN lot (`name`) ou PLUSIEURS en une requête (`names`, une liste collée ou une
     * quantité). Le plafond de 500 n'est qu'un garde contre une charge absurde : la capacité de la grille, elle, est jugée par le service.
     */
    public const LOT_RULES = [
        'name' => ['required_without:names', 'string', 'min:1', 'max:50'],
        'names' => ['required_without:name', 'array', 'min:1', 'max:500'],
        'names.*' => ['string', 'min:1', 'max:50'],
    ];

    public function __construct(
        private readonly RewardsGridWorkflow $workflow,
        private readonly RewardsGridReader $reader,
    ) {}

    /** Page publique par SLUG, lecture seule. Les deux modes. Visible depuis `drawed`. */
    public function show(string $slug): JsonResponse
    {
        $grid = AnimRewardsGrid::where('slug', $slug)->first();
        if (! $grid) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        if (! $grid->isPublic()) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid)]);
    }

    /** Les grilles PRIVÉES de ce personnage, avec accès organisateur complet. */
    public function index(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);
        if (! $character) {
            return AnimApiErrors::error(404, 'anim.api.character_not_found');
        }

        $grids = AnimRewardsGrid::where('character_id', $character->id)->orderByDesc('id')->get()
            ->map(fn (AnimRewardsGrid $g) => $this->reader->read($g, forOwner: true))->all();

        return response()->json(['success' => true, 'grids' => $grids]);
    }

    public function store(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);
        if (! $character) {
            return AnimApiErrors::error(404, 'anim.api.character_not_found');
        }

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'width' => ['required', 'integer'],
            'height' => ['required', 'integer'],
            'kingdom_id' => ['nullable', 'integer', 'exists:rk_kingdoms,id'],
            'province_id' => ['nullable', 'integer', 'exists:rk_provinces,id'],
            'city_id' => ['nullable', 'integer', 'exists:rk_cities,id'],
        ]);

        if (empty($data['kingdom_id']) && empty($data['province_id']) && empty($data['city_id'])) {
            throw MandateRefusal::make('place', 'anim.api.place_required');
        }

        $type = AnimActivityType::where('key', 'rewards')->firstOrFail();
        $grid = $this->workflow->create($character, $type, $data);

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid, forOwner: true)], 201);
    }

    public function addLot(Request $request, int $grid): JsonResponse
    {
        return $this->manage($request, $grid, function ($owner, $grid) use ($request) {
            $data = $request->validate(self::LOT_RULES);

            // Un lot (`name`) ou plusieurs en une requête (`names`).
            return isset($data['names'])
                ? $this->workflow->addLots($owner, $grid, $data['names'])
                : $this->workflow->addLot($owner, $grid, $data['name']);
        }, 201);
    }

    public function removeLot(Request $request, int $grid, int $lot): JsonResponse
    {
        return $this->manage($request, $grid, function ($owner, $grid) use ($lot) {
            $lotModel = AnimRewardsList::find($lot);
            if (! $lotModel || $lotModel->grid_id !== $grid->id) {
                return AnimApiErrors::error(404, 'anim.api.lot_not_found');
            }
            $this->workflow->removeLot($owner, $grid, $lotModel);
        });
    }

    public function draw(Request $request, int $grid): JsonResponse
    {
        return $this->manage($request, $grid, fn ($owner, $grid) => $this->workflow->draw($owner, $grid));
    }

    public function assign(Request $request, int $grid, int $lot): JsonResponse
    {
        return $this->manage($request, $grid, function ($owner, $grid) use ($request, $lot) {
            $data = $request->validate(['player' => ['nullable', 'string', 'max:40']]);
            $lotModel = AnimRewardsList::find($lot);
            if (! $lotModel || $lotModel->grid_id !== $grid->id) {
                return AnimApiErrors::error(404, 'anim.api.lot_not_found');
            }

            return $this->workflow->assign($owner, $grid, $lotModel, $data['player'] ?? null);
        });
    }

    public function markTaken(Request $request, int $grid, int $lot): JsonResponse
    {
        return $this->manage($request, $grid, function ($owner, $grid) use ($request, $lot) {
            $data = $request->validate(['is_taken' => ['required', 'boolean']]);
            $lotModel = AnimRewardsList::find($lot);
            if (! $lotModel || $lotModel->grid_id !== $grid->id) {
                return AnimApiErrors::error(404, 'anim.api.lot_not_found');
            }

            return $this->workflow->markTaken($owner, $grid, $lotModel, (bool) $data['is_taken']);
        });
    }

    public function reopen(Request $request, int $grid): JsonResponse
    {
        return $this->manage($request, $grid, fn ($owner, $grid) => $this->workflow->reopen($owner, $grid));
    }

    public function close(Request $request, int $grid): JsonResponse
    {
        return $this->manage($request, $grid, fn ($owner, $grid) => $this->workflow->close($owner, $grid));
    }

    public function destroy(Request $request, int $grid): JsonResponse
    {
        $gridModel = AnimRewardsGrid::find($grid);
        if (! $gridModel) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        $this->workflow->delete($this->workflow->ownerOf($request->user(), $gridModel), $gridModel);

        return response()->json(['success' => true]);
    }

    /** Un geste de gestion : retrouve la grille, l'organisateur du compte, applique, renvoie l'état. */
    private function manage(Request $request, int $id, callable $gesture, int $status = 200): JsonResponse
    {
        $grid = AnimRewardsGrid::find($id);
        if (! $grid) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        $result = $gesture($this->workflow->ownerOf($request->user(), $grid), $grid);

        // Si le geste a renvoyé une réponse d'erreur, la retourner directement.
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid->refresh(), forOwner: true)], $status);
    }
}
