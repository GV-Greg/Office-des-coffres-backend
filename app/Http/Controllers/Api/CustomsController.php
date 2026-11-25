<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\City;
use App\Models\CustomsOfficer;
use App\Services\CustomsOfficers;
use App\Services\MandateAuthority;
use App\Support\MandateApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API des douaniers (module Sécurité). Ne valide que la FORME : les règles vivent
 * dans App\Services\CustomsOfficers et MandateAuthority. Toujours les personnages du porteur du jeton : un personnage d'un autre
 * compte répond 404, jamais 403.
 */
class CustomsController extends Controller
{
    public function __construct(
        private readonly CustomsOfficers $officers,
        private readonly MandateAuthority $authority,
    ) {}

    /**
     * État du personnage : sa demande ou son accès, ce qu'il peut décider (province + niveau) et, pour qui décide, les demandes en
     * attente, les douaniers de la province et le réglage d'inactivité. `has_security_trio` sert l'écran : « aucun membre de la
     * sécurité n'est connu de l'Office pour votre province » est l'état COURANT d'une province (limite 2 de MandateAuthority).
     */
    public function show(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $officer = CustomsOfficer::query()->where('character_id', $own->id)->where('active', 1)->first();
        $scope = $this->authority->customsDeciderScope($own);

        $payload = [
            'success' => true,
            'officer' => $officer ? $this->officers->present($officer) : null,
            'can_request' => $own->is_validated && ! $officer,
            'decider' => null,
        ];

        if ($scope) {
            $province = $scope['province'];
            $payload['decider'] = [
                'level' => $scope['level'],
                'province' => ['id' => $province->id, 'name' => $province->province_name],
                'has_security_trio' => $this->authority->provinceHasSecurityTrio($province),
                'inactivity_days' => $this->officers->inactivityDays($province),
                'inactivity_default' => CustomsOfficer::DEFAULT_INACTIVITY_DAYS,
                'inactivity_min' => CustomsOfficer::MIN_INACTIVITY_DAYS,
                'inactivity_max' => CustomsOfficer::MAX_INACTIVITY_DAYS,
                'pending' => $this->listFor($province->id, CustomsOfficer::PENDING),
                'officers' => $this->listFor($province->id, CustomsOfficer::APPROVED),
            ];
        }

        return response()->json($payload);
    }

    public function store(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $data = $request->validate(['city_id' => ['required', 'integer', 'exists:rk_cities,id']]);
        $officer = $this->officers->request($own, City::findOrFail($data['city_id']));

        return response()->json(['success' => true, 'officer' => $this->officers->present($officer)], 201);
    }

    /** Retire sa demande (en attente) ou son propre accès (en vigueur). */
    public function destroy(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $this->officers->withdraw($own);

        return response()->json(['success' => true]);
    }

    public function settings(Request $request, int $character): JsonResponse
    {
        $own = $request->user()->characters()->find($character);
        if (! $own) {
            return MandateApiErrors::error(404, 'mandates.api.character_not_found');
        }

        $scope = $this->authority->customsDeciderScope($own);
        if (! $scope) {
            return MandateApiErrors::error(403, 'security.api.not_authorized');
        }

        $data = $request->validate(['inactivity_days' => ['required', 'integer']]);
        $this->officers->setInactivityDays($own, $scope['province'], (int) $data['inactivity_days']);

        return response()->json(['success' => true, 'inactivity_days' => $this->officers->inactivityDays($scope['province'])]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, true);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, false);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $officer = CustomsOfficer::query()->find($id);
        $actor = $officer ? $this->actorFor($request, $officer) : null;
        if (! $officer || ! $actor) {
            return $this->notFoundOrForbidden($officer);
        }

        return response()->json(['success' => true, 'officer' => $this->officers->present($this->officers->revoke($officer, $actor), true)]);
    }

    private function decide(Request $request, int $id, bool $approve): JsonResponse
    {
        $officer = CustomsOfficer::query()->find($id);
        $actor = $officer ? $this->actorFor($request, $officer) : null;
        if (! $officer || ! $actor) {
            return $this->notFoundOrForbidden($officer);
        }

        return response()->json(['success' => true, 'officer' => $this->officers->present($this->officers->decide($officer, $actor, $approve), true)]);
    }

    /** Personnage du compte qui décide dans la province de cette ligne (jamais le demandeur lui-même). */
    private function actorFor(Request $request, CustomsOfficer $officer): ?Character
    {
        return $request->user()->characters()->get()->first(
            fn (Character $c) => $c->id !== $officer->character_id
                && $this->authority->customsDeciderLevel($c, $officer->province) !== null
        );
    }

    private function notFoundOrForbidden(?CustomsOfficer $officer): JsonResponse
    {
        // Une ligne d'une province où l'on ne décide pas répond comme une ligne inexistante : on ne confirme pas son existence.
        return MandateApiErrors::error(404, 'security.api.officer_not_found');
    }

    /** @return array<int, array<string, mixed>> */
    private function listFor(int $provinceId, string $status): array
    {
        return CustomsOfficer::query()->where('province_id', $provinceId)->where('status', $status)
            ->with('character', 'province', 'city')->orderBy('created_at')->get()
            ->map(fn (CustomsOfficer $officer) => $this->officers->present($officer, true))->all();
    }
}
