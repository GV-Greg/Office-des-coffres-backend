<?php

namespace App\Http\Controllers\Api\Anim;

use App\Exceptions\MandateRefusal;
use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeSecret;
use App\Services\DecodeAccess;
use App\Services\DecodeActivityReader;
use App\Services\DecodeActor;
use App\Services\DecodeExporter;
use App\Services\DecodePasswordReset;
use App\Services\DecodeProposals;
use App\Services\DecodeWorkflow;
use App\Support\DecodeIp;
use App\Support\DecodeSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décode le code — mode SANS COMPTE (D2). Aucune session ni jeton : le slug (public) et le mot de passe accompagnent
 * chaque geste de gestion (D3, D10) et ne vivent que le temps de la requête. Ne valide que la FORME : les règles sont dans
 * DecodeWorkflow, DecodeAccess, DecodeClosing et DecodePasswordReset.
 *
 * ⚠️ Le mot de passe voyage dans le CORPS (POST), jamais dans l'adresse ni en-tête : c'est pourquoi les exports sont des POST.
 * ⚠️ Aucune route fixe ne doit voisiner `{slug}` au même niveau (aucun mot réservé, D14) : tout ce qui n'est pas un slug vit sous
 * `decode-manage/{slug}/…`, jamais à côté.
 */
class DecodeAnonymousController extends Controller
{
    public function __construct(
        private readonly DecodeWorkflow $workflow,
        private readonly DecodeProposals $proposals,
        private readonly DecodeAccess $access,
        private readonly DecodePasswordReset $reset,
        private readonly DecodeActivityReader $reader,
        private readonly DecodeExporter $exporter,
    ) {}

    /**
     * Aperçu et disponibilité du slug (D14, D26, D27) : la SEULE source des règles, le site ne les recopie pas. `name` donne la
     * proposition ; `slug` (corrigé à la main par l'utilisateur) en dit la validité et la disponibilité. Jamais de suffixe automatique.
     */
    public function slug(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:60'],
        ]);

        $proposal = DecodeSlug::propose((string) $request->query('name', ''));
        $candidate = $request->query('slug') !== null ? (string) $request->query('slug') : $proposal;
        $valid = DecodeSlug::isValid($candidate);

        return response()->json([
            'success' => true,
            'proposal' => $proposal,
            'slug' => $candidate,
            'valid' => $valid,
            'taken' => $valid && DecodeSlug::taken($candidate),
            'min' => DecodeSlug::MIN,
            'max' => DecodeSlug::MAX,
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'min:3', 'max:190'],
            'organizer_name' => ['required', 'string', 'min:1', 'max:30'],
            'password' => ['required', 'string', 'max:200', 'confirmed'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'kingdom_id' => ['nullable', 'integer', 'exists:rk_kingdoms,id'],
            'province_id' => ['nullable', 'integer', 'exists:rk_provinces,id'],
            'city_id' => ['nullable', 'integer', 'exists:rk_cities,id'],
        ]);

        if (empty($data['kingdom_id']) && empty($data['province_id']) && empty($data['city_id'])) {
            throw MandateRefusal::make('place', 'anim.api.place_required');
        }

        $type = AnimActivityType::where('key', 'decode')->firstOrFail();
        $activity = $this->workflow->createAnonymous($type, $data, DecodeIp::fingerprint($request->ip()));

        return response()->json([
            'success' => true,
            'activity' => $this->reader->read($activity->refresh(), forOwner: true),
            'email_pending' => $activity->organizer_email !== null,
        ], 201);
    }

    /** Ouvre la gestion : prouve le mot de passe et rend l'état complet (code du coffre en cours compris, pour l'auteur). */
    public function open(Request $request, string $slug): JsonResponse
    {
        $activity = $this->access->activityOf($this->actor($request, $slug));

        return response()->json(['success' => true, 'activity' => $this->reader->read($activity, forOwner: true)]);
    }

    public function generateSecret(Request $request, string $slug): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $activity = $this->access->activityOf($actor);
        $this->workflow->generateSecret($actor, $activity);

        return response()->json(['success' => true, 'activity' => $this->reader->read($activity->refresh(), forOwner: true)], 201);
    }

    public function propose(Request $request, string $slug, int $secret): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $data = $request->validate([
            'combination' => ['required', 'string', 'regex:/^\d{5}$/'],
            'character_name' => ['required', 'string', 'min:1', 'max:30'],
        ]);

        $activity = $this->access->activityOf($actor);
        $secretModel = AnimDecodeSecret::find($secret) ?? throw MandateRefusal::make('secret', 'anim.api.secret_not_open');
        $result = $this->proposals->submit($actor, $activity, $secretModel, $data['combination'], $data['character_name']);

        return response()->json([
            'success' => true,
            'score' => $result['score'],
            'found' => $result['found'],
            'proposal' => ['combination' => $result['proposal']->combination, 'character_name' => $result['proposal']->character_name, 'points' => $result['proposal']->points],
            'activity' => $this->reader->read($activity->refresh(), forOwner: true),
        ], 201);
    }

    /** Clôture DÉFINITIVE (D19) : photo figée, effacement des données personnelles, compte rendu à l'adresse confirmée. */
    public function close(Request $request, string $slug): JsonResponse
    {
        $actor = $this->actor($request, $slug);
        $activity = $this->access->activityOf($actor);
        $closed = $this->workflow->close($actor, $activity);

        return response()->json(['success' => true, 'activity' => $this->reader->read($closed, forOwner: true)]);
    }

    public function exportRules(Request $request, string $slug): JsonResponse
    {
        return $this->export($request, $slug, fn ($a) => $this->exporter->exportRules($a));
    }

    public function exportResults(Request $request, string $slug): JsonResponse
    {
        return $this->export($request, $slug, fn ($a) => $this->exporter->exportResults($a));
    }

    /** L'auteur demande un lien de réinitialisation (D35). Même réponse que l'activité existe ou non, ait un email ou non. */
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

    /** Lien signé de confirmation de l'adresse (D29). Rappelé en JSON par la page du site. */
    public function confirmEmail(string $slug, string $hash): JsonResponse
    {
        if (! $this->workflow->confirmEmail($slug, $hash)) {
            throw MandateRefusal::make('email', 'anim.api.email_confirm_invalid');
        }

        return response()->json(['success' => true]);
    }

    private function actor(Request $request, string $slug): DecodeActor
    {
        $password = $request->validate(['password' => ['required', 'string', 'max:200']])['password'];

        return $this->access->prove($slug, $password);
    }

    private function export(Request $request, string $slug, callable $build): JsonResponse
    {
        $activity = $this->access->activityOf($this->actor($request, $slug));

        return response()->json(['success' => true, 'bbcode' => $build($activity)]);
    }
}
