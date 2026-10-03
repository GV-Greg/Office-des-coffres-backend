<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CouncilMandate;
use App\Models\CouncilOffice;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Models\Province;
use App\Services\MandateWorkflow;
use App\Services\OfficeHistory;
use App\Support\MandateCalendar;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Administration des mandats (Blade, role:admin). L'administration est la SEULE source d'écriture
 * de ces tables (§5quinquies, « Ces tables sont administrables ») : §5bis ne s'applique pas ici.
 * Aucun texte en dur : tout passe par __() (lang/fr/mandates.php), arbitrage Q5.
 */
class MandateAdminController extends Controller
{
    public function __construct(private readonly MandateWorkflow $workflow) {}

    // ─── Pages ────────────────────────────────────────────────────────────────────────────

    /** File des demandes, la plus ancienne en haut (traitement par lot, §5quinquies). */
    public function pending(): View
    {
        $relations = ['character.user', 'character.city.province', 'renews'];
        $requests = collect()
            ->concat(MayorMandate::where('status', Mandate::PENDING)->with([...$relations, 'city'])->get())
            ->concat(CouncilMandate::where('status', Mandate::PENDING)->with([...$relations, 'province', 'pendingOffice'])->get())
            ->sortBy('created_at')
            ->values();

        $officeRequests = CouncilMandate::where('status', Mandate::APPROVED)
            ->whereNotNull('office_requested_at')
            ->with(['character.user', 'province', 'pendingOffice'])
            ->orderBy('office_requested_at')
            ->get();

        return view('mandates.pending', [
            'requests' => $requests,
            'officeRequests' => $officeRequests,
            'insights' => $requests->mapWithKeys(fn (Mandate $mandate) => [$this->rowKey($mandate) => $this->insights($mandate)]),
            'officeHolders' => $officeRequests->mapWithKeys(fn (CouncilMandate $mandate) => [
                $mandate->id => $mandate->pending_office_id ? $this->currentHolder($mandate->province_id, $mandate->pending_office_id, $mandate->id) : null,
            ]),
        ]);
    }

    /** Mandats en cours, par province puis par ville, avec les gestes de province. */
    public function running(): View
    {
        $council = CouncilMandate::query()->running()
            ->with(['character.user', 'province', 'officePeriods.office', 'pendingOffice'])
            ->get()
            ->sortBy(fn ($mandate) => $mandate->province?->province_name)
            ->groupBy('province_id');

        $mayors = MayorMandate::query()->running()
            ->with(['character.user', 'city.province'])
            ->get()
            ->sortBy(fn ($mandate) => $mandate->city?->city_name)
            ->values();

        return view('mandates.running', [
            'councilByProvince' => $council,
            'mayors' => $mayors,
            'offices' => CouncilOffice::orderBy('position')->get(),
        ]);
    }

    /** Refus et révocations : corriger un motif (tours 15, 17), annuler une révocation (Q27). */
    public function decisions(): View
    {
        $decided = fn (string $model) => $model::query()
            ->whereIn('status', [Mandate::REJECTED, Mandate::REVOKED])
            ->with(['character.user', $model === MayorMandate::class ? 'city' : 'province'])
            ->latest('updated_at')->limit(100)->get();

        return view('mandates.decisions', [
            'decisions' => collect()->concat($decided(MayorMandate::class))->concat($decided(CouncilMandate::class))
                ->sortByDesc('updated_at')->values(),
        ]);
    }

    /**
     * File de vérification des mandats terminés (tour 05, ajout 2) : maires terminés, et
     * conseillers terminés AVEC un poste. Un conseiller sans poste n'y entre pas : il ne
     * détenait aucune autorité (dérivé de Q9). L'email au-delà du seuil arrive au lot 3.
     */
    public function verification(): View
    {
        return view('mandates.verification', ['items' => $this->verificationQueue()]);
    }

    /**
     * Historique des postes d'une province (fil mandats-historique) : le conseil PAR TITRE (Q2), les
     * maires par ville (Q3), via LA fonction de fusion unique (OfficeHistory). Année réelle (Q8).
     */
    public function history(Request $request, OfficeHistory $history): View
    {
        $provinces = Province::query()->with('kingdom')->get()
            ->sortBy([fn ($a, $b) => [$a->kingdom?->kingdom_name, $a->province_name] <=> [$b->kingdom?->kingdom_name, $b->province_name]])
            ->values();
        $province = $provinces->firstWhere('id', (int) $request->query('province'));

        $data = ['provinces' => $provinces, 'province' => $province];
        if ($province !== null) {
            $entries = $history->forProvince($province->id);
            $byCity = $entries['mayors']->groupBy('city_id');
            $cities = $province->cities()->orderBy('city_name')->get();

            $data += [
                'offices' => CouncilOffice::query()->orderBy('position')->get(),
                'council' => $entries['council']->groupBy('office_key'),
                'mayorCities' => $cities->filter(fn ($city) => $byCity->has($city->id))->values(),
                'mayors' => $byCity,
                // Q3 : ne jamais faire lire la liste comme exhaustive — un compteur dépliable.
                'citiesWithout' => $cities->reject(fn ($city) => $byCity->has($city->id))->values(),
            ];
        }

        return view('mandates.history', $data);
    }

    // ─── Gestes ───────────────────────────────────────────────────────────────────────────

    public function approve(Request $request, string $level, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'started_at' => ['nullable', 'date_format:Y-m-d'],
            'in_office_from' => ['nullable', 'date_format:Y-m-d'],
            'replace_holder' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->workflow->approve($this->find($level, $id), $validated);

        return back()->with('status', __('mandates.admin.done.approved'));
    }

    public function reject(Request $request, string $level, int $id): RedirectResponse
    {
        $validated = $this->validateDecision($request, config('mandates.reject_reasons'));
        $this->workflow->reject($this->find($level, $id), $validated['reason'], $validated['message'] ?? null,
            $validated['locale'] ?? null, $validated['note'] ?? null);

        return back()->with('status', __('mandates.admin.done.rejected'));
    }

    public function batchApprove(Request $request): RedirectResponse
    {
        $validated = $request->validate(['items' => ['required', 'array', 'min:1'], 'items.*' => ['string', 'regex:/^(mayor|council):[0-9]+$/']]);
        $mandates = array_map(function (string $item) {
            [$level, $id] = explode(':', $item);

            return $this->find($level, (int) $id);
        }, $validated['items']);

        $count = $this->workflow->approveRenewals($mandates);

        return back()->with('status', __('mandates.admin.done.batch', ['count' => $count]));
    }

    public function revoke(Request $request, string $level, int $id): RedirectResponse
    {
        $validated = $this->validateDecision($request, config('mandates.revoke_reasons'), withEffective: true);
        $this->workflow->revoke($this->find($level, $id), $validated['reason'], $validated['effective_at'] ?? null,
            $validated['message'] ?? null, $validated['locale'] ?? null, $validated['note'] ?? null);

        return back()->with('status', __('mandates.admin.done.revoked'));
    }

    public function unrevoke(Request $request, string $level, int $id): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->workflow->unrevoke($this->find($level, $id), $validated['note'] ?? null);

        return back()->with('status', __('mandates.admin.done.unrevoked'));
    }

    public function correctDecision(Request $request, string $level, int $id): RedirectResponse
    {
        $mandate = $this->find($level, $id);
        $reasons = $mandate->status === Mandate::REJECTED ? config('mandates.reject_reasons') : config('mandates.revoke_reasons');
        $validated = $this->validateDecision($request, $reasons);
        $this->workflow->correctDecision($mandate, $validated['reason'], $validated['message'] ?? null, $validated['locale'] ?? null);

        return back()->with('status', __('mandates.admin.done.decision_corrected'));
    }

    public function correctStart(Request $request, string $level, int $id): RedirectResponse
    {
        $validated = $request->validate(['started_at' => ['required', 'date_format:Y-m-d']]);
        $followed = $this->workflow->correctStart($this->find($level, $id), $validated['started_at']);

        return back()->with('status', $followed
            ? __('mandates.admin.done.start_corrected')
            : __('mandates.admin.done.start_corrected_end_kept'));
    }

    public function correctInOffice(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate(['in_office_from' => ['required', 'date_format:Y-m-d']]);
        $this->workflow->correctInOffice($this->findCouncil($id), $validated['in_office_from']);

        return back()->with('status', __('mandates.admin.done.in_office_corrected'));
    }

    public function setOffice(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'council_office_key' => ['nullable', 'string', 'exists:council_offices,key'],
            'reason' => ['nullable', Rule::in(config('mandates.office_remove_reasons'))],
            'message' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', Rule::in(['fr', 'en'])],
        ]);
        $this->workflow->setOffice($this->findCouncil($id), $validated['council_office_key'] ?? null,
            $validated['reason'] ?? null, $validated['message'] ?? null, $validated['locale'] ?? null);

        return back()->with('status', __('mandates.admin.done.office'));
    }

    public function approveOfficeRequest(int $id): RedirectResponse
    {
        $this->workflow->approveOfficeRequest($this->findCouncil($id));

        return back()->with('status', __('mandates.admin.done.office'));
    }

    public function rejectOfficeRequest(Request $request, int $id): RedirectResponse
    {
        $validated = $this->validateDecision($request, config('mandates.reject_reasons'));
        $this->workflow->rejectOfficeRequest($this->findCouncil($id), $validated['reason'], $validated['message'] ?? null, $validated['locale'] ?? null);

        return back()->with('status', __('mandates.admin.done.office_rejected'));
    }

    public function verify(string $level, int $id): RedirectResponse
    {
        $this->workflow->verify($this->find($level, $id));

        return back()->with('status', __('mandates.admin.done.verified'));
    }

    public function extend(Request $request, Province $province): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $count = $this->workflow->extendProvince($province, $validated['note']);

        return back()->with('status', __('mandates.admin.done.extended', ['count' => $count]));
    }

    public function handover(Request $request, Province $province): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'outgoing' => ['nullable', 'array'], 'outgoing.*' => ['integer'],
            'incoming' => ['nullable', 'array'], 'incoming.*' => ['integer'],
        ]);
        $this->workflow->handover($province, $validated['date'], $validated['outgoing'] ?? [], $validated['incoming'] ?? []);

        return back()->with('status', __('mandates.admin.done.handover'));
    }

    public function clearOffices(Request $request, Province $province): RedirectResponse
    {
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $count = $this->workflow->clearOffices($province, $validated['date']);

        return back()->with('status', __('mandates.admin.done.offices_cleared', ['count' => $count]));
    }

    // ─── Outils ───────────────────────────────────────────────────────────────────────────

    /** Nombre affiché dans la navigation : demandes et déclarations de poste en attente. */
    public static function pendingCount(): int
    {
        return MayorMandate::where('status', Mandate::PENDING)->count()
            + CouncilMandate::where('status', Mandate::PENDING)->count()
            + CouncilMandate::where('status', Mandate::APPROVED)->whereNotNull('office_requested_at')->count();
    }

    /** @return Collection<int, array{mandate: Mandate, ended_at: Carbon, days: int, overdue: bool}> */
    public function verificationQueue(): Collection
    {
        $now = Carbon::now();
        $ended = fn ($query) => $query->whereNull('verified_at')->where(fn ($q) => $q
            ->where('status', Mandate::REVOKED)
            ->orWhere(fn ($approved) => $approved->where('status', Mandate::APPROVED)->where('holds_until', '<=', $now)));

        $mayors = MayorMandate::query()->where($ended)->with(['character.user', 'city'])->get();
        $council = CouncilMandate::query()->where($ended)
            ->whereHas('officePeriods', fn ($periods) => $periods->where('end_reason', 'fin_du_mandat'))
            ->with(['character.user', 'province', 'officePeriods.office'])->get();

        return collect()->concat($mayors)->concat($council)
            ->map(function (Mandate $mandate) use ($now) {
                $days = (int) $mandate->holds_until->diffInDays($now);

                return [
                    'mandate' => $mandate,
                    'ended_at' => $mandate->holds_until,
                    'days' => $days,
                    'overdue' => $days >= config('mandates.verification_days.'.$mandate::LEVEL),
                ];
            })
            ->sortBy('ended_at')
            ->values();
    }

    private function find(string $level, int $id): Mandate
    {
        abort_unless(in_array($level, ['mayor', 'council'], true), 404);

        return MandateWorkflow::modelFor($level)::findOrFail($id);
    }

    private function findCouncil(int $id): CouncilMandate
    {
        return CouncilMandate::findOrFail($id);
    }

    private function rowKey(Mandate $mandate): string
    {
        return $mandate::LEVEL.':'.$mandate->id;
    }

    /** @return array<string, mixed> */
    private function validateDecision(Request $request, array $reasons, bool $withEffective = false): array
    {
        return $request->validate([
            'reason' => ['required', Rule::in($reasons)],
            'message' => ['nullable', 'string', 'max:2000', 'required_if:reason,autre'],
            'locale' => ['nullable', Rule::in(['fr', 'en'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ] + ($withEffective ? ['effective_at' => ['nullable', 'date_format:Y-m-d']] : []),
            ['message.required_if' => __('mandates.admin.errors.comment_required')]);
    }

    /** Ce que la file affiche à côté d'une demande. Signale, ne bloque pas (règle 4). */
    private function insights(Mandate $mandate): array
    {
        $level = $mandate::LEVEL;
        $validUntil = MandateCalendar::validUntil($level, $mandate->started_at);
        $holdsUntil = MandateCalendar::nominalHoldsUntil($level, $validUntil);
        $residenceCity = $mandate->character?->city;

        $insights = [
            'valid_until' => $validUntil,
            'dead_born' => $holdsUntil->lessThanOrEqualTo(Carbon::now()),
            'renewal' => $mandate->renews_id !== null,
            'renewed_revoked' => $mandate->renews?->status === Mandate::REVOKED,
            'seat_holders' => collect(),
            'outgoing_extended' => false,
            'unassigned_over' => false,
            'title_holder' => null,
        ];

        if ($mandate instanceof MayorMandate) {
            $insights['off_residence'] = $residenceCity?->id !== $mandate->city_id;
            $insights['seat_holders'] = MayorMandate::query()->running()->where('city_id', $mandate->city_id)
                ->whereKeyNot($mandate->id)->with('character')->get();

            return $insights;
        }

        /** @var CouncilMandate $mandate */
        $insights['off_residence'] = $residenceCity?->province_id !== $mandate->province_id;
        $insights['outgoing_extended'] = CouncilMandate::query()->inOfficeNow()->where('status', Mandate::APPROVED)
            ->where('province_id', $mandate->province_id)->where('valid_until', '<=', Carbon::now())->exists();
        $inOffice = CouncilMandate::query()->inOfficeNow()->where('status', Mandate::APPROVED)
            ->where('province_id', $mandate->province_id)->with('officePeriods')->get();
        $unassigned = $inOffice->filter(fn (CouncilMandate $member) => $member->currentPeriod() === null)->count();
        $insights['unassigned_over'] = $unassigned > config('mandates.council_unassigned_seats');
        if ($mandate->pending_office_id) {
            $insights['title_holder'] = $this->currentHolder($mandate->province_id, $mandate->pending_office_id, $mandate->id);
        }

        return $insights;
    }

    /** Détenteur actuel d'un poste dans une province, ou null. */
    private function currentHolder(int $provinceId, int $officeId, int $exceptMandateId): ?CouncilMandate
    {
        $now = Carbon::now();

        return CouncilMandate::query()
            ->where('province_id', $provinceId)
            ->whereKeyNot($exceptMandateId)
            ->whereHas('officePeriods', fn ($periods) => $periods->where('council_office_id', $officeId)
                ->where('started_at', '<=', $now)->where('ended_at', '>', $now))
            ->with('character')
            ->first();
    }
}
