<?php

namespace App\Http\Controllers\Api\Anim;

use App\Exceptions\MandateRefusal;
use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Services\AnimAccess;
use App\Services\AnimActor;
use App\Services\RewardsGridPasswordReset;
use App\Services\RewardsGridReader;
use App\Services\RewardsGridWorkflow;
use App\Support\AnimApiErrors;
use App\Support\AnimIp;
use App\Support\AnimSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grille des lots — mode SANS COMPTE. Aucune session ni jeton : le slug (public) et le mot de passe accompagnent
 * chaque geste de gestion et ne vivent que le temps de la requête. Ne valide que la FORME : les règles sont dans
 * RewardsGridWorkflow, AnimAccess et RewardsGridPasswordReset.
 *
 * ⚠️ Le mot de passe voyage dans le CORPS (POST), jamais dans l'adresse.
 * ⚠️ Aucune route fixe ne doit voisiner `{slug}` au même niveau (aucun mot réservé, D14) : tout ce qui n'est pas un
 * slug vit sous `rewards-manage/{slug}/…`, jamais à côté.
 * ⚠️ Routes ajoutées : route:cache en prod.
 */
class RewardsAnonymousController extends Controller
{
    public function __construct(
        private readonly RewardsGridWorkflow $workflow,
        private readonly AnimAccess $access,
        private readonly RewardsGridPasswordReset $reset,
        private readonly RewardsGridReader $reader,
    ) {}

    /**
     * Aperçu et disponibilité du slug : la SEULE source des règles. Jamais de suffixe automatique.
     */
    public function slug(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:60'],
        ]);

        $proposal = AnimSlug::propose((string) $request->query('name', ''));
        $candidate = $request->query('slug') !== null ? (string) $request->query('slug') : $proposal;
        $valid = AnimSlug::isValid($candidate);

        return response()->json([
            'success' => true,
            'proposal' => $proposal,
            'slug' => $candidate,
            'valid' => $valid,
            'taken' => $valid && AnimSlug::taken($candidate, AnimRewardsGrid::class),
            'min' => AnimSlug::MIN,
            'max' => AnimSlug::MAX,
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'width' => ['required', 'integer'],
            'height' => ['required', 'integer'],
            'organizer_name' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:200', 'confirmed'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'kingdom_id' => ['nullable', 'integer', 'exists:rk_kingdoms,id'],
            'province_id' => ['nullable', 'integer', 'exists:rk_provinces,id'],
            'city_id' => ['nullable', 'integer', 'exists:rk_cities,id'],
        ]);

        if (empty($data['kingdom_id']) && empty($data['province_id']) && empty($data['city_id'])) {
            throw MandateRefusal::make('place', 'anim.api.place_required');
        }

        $type = AnimActivityType::where('key', 'rewards')->firstOrFail();
        $grid = $this->workflow->createAnonymous($type, $data, AnimIp::fingerprint($request->ip(), 'anim-rewards-ip'));

        return response()->json([
            'success' => true,
            'grid' => $this->reader->read($grid->refresh(), forOwner: true),
            'email_pending' => $grid->organizer_email !== null,
        ], 201);
    }

    /** Ouvre la gestion : prouve le mot de passe et rend l'état complet. */
    public function open(Request $request, string $slug): JsonResponse
    {
        $grid = $this->access->activityOf($this->actor($request, $slug));

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid, forOwner: true)]);
    }

    public function addLot(Request $request, string $slug): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $grid = $this->access->activityOf($actor);
        $data = $request->validate(RewardsGridController::LOT_RULES);

        // Un lot (`name`) ou plusieurs en une requête (`names`).
        if (isset($data['names'])) {
            $this->workflow->addLots($actor, $grid, $data['names']);
        } else {
            $this->workflow->addLot($actor, $grid, $data['name']);
        }

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid->refresh(), forOwner: true)], 201);
    }

    public function removeLot(Request $request, string $slug, int $lot): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $grid = $this->access->activityOf($actor);
        $lotModel = AnimRewardsList::find($lot);
        if (! $lotModel || $lotModel->grid_id !== $grid->id) {
            return AnimApiErrors::error(404, 'anim.api.lot_not_found');
        }
        $this->workflow->removeLot($actor, $grid, $lotModel);

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid->refresh(), forOwner: true)]);
    }

    public function draw(Request $request, string $slug): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $grid = $this->access->activityOf($actor);
        $this->workflow->draw($actor, $grid);

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid->refresh(), forOwner: true)]);
    }

    public function assign(Request $request, string $slug, int $lot): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $data = $request->validate(['player' => ['nullable', 'string', 'max:40']]);
        $grid = $this->access->activityOf($actor);
        $lotModel = AnimRewardsList::find($lot);
        if (! $lotModel || $lotModel->grid_id !== $grid->id) {
            return AnimApiErrors::error(404, 'anim.api.lot_not_found');
        }
        $this->workflow->assign($actor, $grid, $lotModel, $data['player'] ?? null);

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid->refresh(), forOwner: true)]);
    }

    public function markTaken(Request $request, string $slug, int $lot): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $data = $request->validate(['is_taken' => ['required', 'boolean']]);
        $grid = $this->access->activityOf($actor);
        $lotModel = AnimRewardsList::find($lot);
        if (! $lotModel || $lotModel->grid_id !== $grid->id) {
            return AnimApiErrors::error(404, 'anim.api.lot_not_found');
        }
        $this->workflow->markTaken($actor, $grid, $lotModel, (bool) $data['is_taken']);

        return response()->json(['success' => true, 'grid' => $this->reader->read($grid->refresh(), forOwner: true)]);
    }

    /** Clôture DÉFINITIVE : photo figée, effacement des données personnelles. */
    public function close(Request $request, string $slug): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $grid = $this->access->activityOf($actor);
        $closed = $this->workflow->close($actor, $grid);

        return response()->json(['success' => true, 'grid' => $this->reader->read($closed, forOwner: true)]);
    }

    /** L'auteur demande un lien de réinitialisation. Même réponse que la grille existe ou non. */
    public function resetRequest(string $slug): JsonResponse
    {
        $this->reset->requestByAuthor($slug);

        return response()->json(['success' => true], 202);
    }

    /** L'auteur choisit son nouveau mot de passe avec le lien à usage unique. */
    public function resetComplete(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200', 'confirmed'],
        ]);

        $this->reset->complete($slug, $data['token'], $data['password']);

        return response()->json(['success' => true]);
    }

    /** Lien signé de confirmation de l'adresse, rappelé en JSON par la page du site. */
    public function confirmEmail(string $slug, string $hash): JsonResponse
    {
        if (! $this->workflow->confirmEmail($slug, $hash)) {
            throw MandateRefusal::make('email', 'anim.api.email_confirm_invalid');
        }

        return response()->json(['success' => true]);
    }

    private function actor(Request $request, string $slug): AnimActor
    {
        $password = $request->validate(['password' => ['required', 'string', 'max:200']])['password'];

        return $this->access->prove($slug, $password);
    }
}
