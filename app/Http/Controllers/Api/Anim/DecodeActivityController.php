<?php

namespace App\Http\Controllers\Api\Anim;

use App\Exceptions\MandateRefusal;
use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeSecret;
use App\Services\DecodeActivityReader;
use App\Services\DecodeExporter;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;
use App\Support\AnimApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décode le code — API du mode PRIVÉ (lié à un personnage, `auth:api`) et lecture publique des deux modes. Ne valide que
 * la FORME : toute règle (propriété, un seul niveau de lieu, slug valable et libre, une activité active par type, suppression
 * d'une activité close seulement…) vit dans App\Services\DecodeWorkflow et DecodeProposals. Erreurs au contrat d'AnimApiErrors.
 * Le mode sans compte a son propre contrôleur (DecodeAnonymousController).
 */
class DecodeActivityController extends Controller
{
    public function __construct(
        private readonly DecodeWorkflow $workflow,
        private readonly DecodeProposals $proposals,
        private readonly DecodeActivityReader $reader,
        private readonly DecodeExporter $exporter,
    ) {}

    /** Types ouverts : l'écran n'affiche pas les autres. */
    public function types(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'types' => AnimActivityType::where('is_active', true)->orderBy('id')->pluck('key')->all(),
        ]);
    }

    /** Page publique par SLUG (D8), lecture seule : jamais le code d'un coffre encore scellé. Les deux modes. */
    public function show(string $slug): JsonResponse
    {
        $activity = AnimDecodeActivity::where('slug', $slug)->first();
        if (! $activity) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }

        return response()->json(['success' => true, 'activity' => $this->reader->read($activity)]);
    }

    /** Les activités PRIVÉES de ce personnage, avec le code du coffre en cours (organisateur seul). */
    public function index(Request $request, int $character): JsonResponse
    {
        $character = $request->user()->characters()->find($character);
        if (! $character) {
            return AnimApiErrors::error(404, 'anim.api.character_not_found');
        }

        $activities = AnimDecodeActivity::where('character_id', $character->id)->orderByDesc('id')->get()
            ->map(fn (AnimDecodeActivity $a) => $this->reader->read($a, forOwner: true))->all();

        return response()->json(['success' => true, 'activities' => $activities]);
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
            'kingdom_id' => ['nullable', 'integer', 'exists:rk_kingdoms,id'],
            'province_id' => ['nullable', 'integer', 'exists:rk_provinces,id'],
            'city_id' => ['nullable', 'integer', 'exists:rk_cities,id'],
        ]);

        if (empty($data['kingdom_id']) && empty($data['province_id']) && empty($data['city_id'])) {
            throw MandateRefusal::make('place', 'anim.api.place_required');
        }

        $type = AnimActivityType::where('key', 'decode')->firstOrFail();
        $activity = $this->workflow->create($character, $type, $data);

        return response()->json(['success' => true, 'activity' => $this->reader->read($activity, forOwner: true)], 201);
    }

    public function generateSecret(Request $request, int $activity): JsonResponse
    {
        return $this->manage($request, $activity, function ($owner, $activity) {
            $this->workflow->generateSecret($owner, $activity);
        }, 201);
    }

    public function propose(Request $request, int $activity, int $secret): JsonResponse
    {
        $data = $request->validate([
            'combination' => ['required', 'string', 'regex:/^\d{5}$/'],
            'character_name' => ['required', 'string', 'min:1', 'max:30'],
        ]);

        $activityModel = AnimDecodeActivity::find($activity);
        if (! $activityModel) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        $owner = $this->workflow->ownerOf($request->user(), $activityModel);
        $secretModel = AnimDecodeSecret::find($secret);
        if (! $secretModel) {
            return AnimApiErrors::error(404, 'anim.api.secret_not_open');
        }

        $result = $this->proposals->submit($owner, $activityModel, $secretModel, $data['combination'], $data['character_name']);

        return response()->json([
            'success' => true,
            'score' => $result['score'],
            'found' => $result['found'],
            'proposal' => ['combination' => $result['proposal']->combination, 'character_name' => $result['proposal']->character_name, 'points' => $result['proposal']->points],
            'activity' => $this->reader->read($activityModel->refresh(), forOwner: true),
        ], 201);
    }

    public function reopen(Request $request, int $activity): JsonResponse
    {
        return $this->manage($request, $activity, fn ($owner, $activity) => $this->workflow->reopen($owner, $activity));
    }

    public function close(Request $request, int $activity): JsonResponse
    {
        return $this->manage($request, $activity, fn ($owner, $activity) => $this->workflow->close($owner, $activity));
    }

    public function destroy(Request $request, int $activity): JsonResponse
    {
        $activity = AnimDecodeActivity::find($activity);
        if (! $activity) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        $this->workflow->delete($this->workflow->ownerOf($request->user(), $activity), $activity);

        return response()->json(['success' => true]);
    }

    public function exportRules(Request $request, int $activity): JsonResponse
    {
        return $this->export($request, $activity, fn ($a) => $this->exporter->exportRules($a));
    }

    public function exportResults(Request $request, int $activity): JsonResponse
    {
        return $this->export($request, $activity, fn ($a) => $this->exporter->exportResults($a));
    }

    /** Un geste de gestion : retrouve l'activité, l'organisateur du compte, applique, renvoie l'état. */
    private function manage(Request $request, int $id, callable $gesture, int $status = 200): JsonResponse
    {
        $activity = AnimDecodeActivity::find($id);
        if (! $activity) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        $gesture($this->workflow->ownerOf($request->user(), $activity), $activity);

        return response()->json(['success' => true, 'activity' => $this->reader->read($activity->refresh(), forOwner: true)], $status);
    }

    private function export(Request $request, int $id, callable $build): JsonResponse
    {
        $activity = AnimDecodeActivity::find($id);
        if (! $activity) {
            return AnimApiErrors::error(404, 'anim.api.activity_not_found');
        }
        $this->workflow->ownerOf($request->user(), $activity);

        return response()->json(['success' => true, 'bbcode' => $build($activity)]);
    }
}
