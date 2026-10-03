<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\CouncilOffice;
use App\Models\CouncilOfficePeriod;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Models\Province;
use App\Notifications\MandateDecision;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tous les gestes qui écrivent un mandat ou un poste — ceux du joueur et ceux de l'administrateur.
 * Arbitrages : admin/content/brief-mandats.md et admin/echanges/mandats-lot1/ (Q1 à Q29).
 *
 * Trois invariants tiennent ici, et seulement ici :
 *  1. holds_until, holds_until_set_by et l'ended_at de la période de poste en cours s'écrivent
 *     ENSEMBLE, par setHoldsUntil() (Q19, Q25). Aucune autre méthode ne les touche.
 *  2. Une période fermée n'est jamais rouverte (Q23), ni réécrite (Q28) : un geste qui
 *     l'exigerait est refusé avec un message qui nomme la période.
 *  3. Un poste ne se pose que sur un mandat EN FONCTION (tour 07), et il est exclusif dans la
 *     province : le gagner le retire à son détenteur, à la même seconde (tour 05).
 *
 * Les emails partent APRÈS la transaction : un échec d'envoi n'annule pas une décision.
 */
class MandateWorkflow
{
    /** @var array<int, array{0: Mandate, 1: MandateDecision}> */
    private array $outbox = [];

    // ─── Lecture ──────────────────────────────────────────────────────────────────────────

    /** @return class-string<Mandate> */
    public static function modelFor(string $level): string
    {
        return match ($level) {
            'mayor' => MayorMandate::class,
            'council' => CouncilMandate::class,
        };
    }

    /** Mandat d'un personnage de ce compte, ou null (404 pour un autre compte : on ne confirme rien). */
    public function findForUser(string $level, int $id, int $userId): ?Mandate
    {
        return self::modelFor($level)::query()
            ->whereKey($id)
            ->whereHas('character', fn ($query) => $query->where('user_id', $userId))
            ->first();
    }

    /**
     * Un mandat se renouvelle s'il est approuvé et actif, en prolongation, ou terminé depuis
     * renewal_window_days au plus (règle 6). Jamais un révoqué.
     */
    public function isRenewable(Mandate $mandate): bool
    {
        if ($mandate->status !== Mandate::APPROVED || $mandate->holds_until === null) {
            return false;
        }

        if (in_array($mandate->effectiveStatus(), ['active', 'extended'], true)) {
            return true;
        }

        return $mandate->effectiveStatus() === 'expired'
            && $mandate->holds_until->greaterThanOrEqualTo(
                Carbon::now()->subDays(config('mandates.renewal_window_days')));
    }

    /**
     * Le personnage peut-il demander un poste à ce niveau, et sinon pourquoi (fil mandats-lot2, Q6) ?
     * C'est LA règle de la demande, exposée telle quelle à l'API : le frontend ne la recode pas.
     * `blocked_by` : not_validated | pending | elected | active | extended | null. Un mandat expiré ne
     * bloque rien — « Renouveler » se lit sur le `renewable` de chaque mandat (Q7 : on ne masque rien).
     *
     * @return array{can_request: bool, blocked_by: ?string}
     */
    public function requestability(Character $character, string $level): array
    {
        if (! $character->is_validated) {
            return ['can_request' => false, 'blocked_by' => 'not_validated'];
        }

        $blocking = $this->blockingMandate($character, $level);

        return [
            'can_request' => $blocking === null,
            'blocked_by' => $blocking?->effectiveStatus(),
        ];
    }

    /** Demande en attente ou mandat en cours (élu, actif, en prolongation) qui bloque une demande. */
    private function blockingMandate(Character $character, string $level): ?Mandate
    {
        return self::modelFor($level)::query()
            ->where('character_id', $character->id)
            ->where(fn ($query) => $query->where('status', Mandate::PENDING)
                ->orWhere(fn ($approved) => $approved->where('status', Mandate::APPROVED)
                    ->where('holds_until', '>', Carbon::now())))
            ->first();
    }

    // ─── Gestes du joueur ─────────────────────────────────────────────────────────────────

    /**
     * Nouvelle demande. Le lieu peut différer de la résidence et n'en est jamais déduit.
     *
     * @param  array{city_id?: int, province_id?: int, council_office_key?: ?string, started_at: string, announcement_url: string}  $data
     */
    public function request(Character $character, string $level, array $data): Mandate
    {
        if (! $character->is_validated) {
            throw $this->refuse('character', 'mandates.api.character_not_validated');
        }

        $this->assertStartAcceptable($level, $data['started_at'], 'started_at');
        $this->assertNoRunningAtLevel($character, $level);

        $attributes = [
            'character_id' => $character->id,
            'status' => Mandate::PENDING,
            'declared_started_at' => $data['started_at'],
            'started_at' => $data['started_at'],
            'announcement_url' => $data['announcement_url'],
        ];

        if ($level === 'mayor') {
            return MayorMandate::create($attributes + ['city_id' => $data['city_id']]);
        }

        // Titre FACULTATIF à la première inscription (tour 05, conséquence 4) : rangé comme une
        // demande de poste, et tranché à l'approbation selon que le mandat entre en fonction (Q15).
        $officeKey = $data['council_office_key'] ?? null;

        return CouncilMandate::create($attributes + [
            'province_id' => $data['province_id'],
            'pending_office_id' => $officeKey ? CouncilOffice::where('key', $officeKey)->value('id') : null,
            'office_requested_at' => $officeKey ? Carbon::now() : null,
        ]);
    }

    /**
     * Renouvellement : même niveau, même lieu, lien d'annonce obligatoire. JAMAIS de poste (Q14-C) :
     * tous les postes sont vidés à l'élection du dirigeant, un renouvellement qui reprendrait
     * l'ancien serait faux à coup sûr.
     */
    public function renew(Mandate $mandate, string $startedAt, string $announcementUrl): Mandate
    {
        if (! $this->isRenewable($mandate)) {
            throw $this->refuse('mandate', 'mandates.api.not_renewable', [
                'days' => config('mandates.renewal_window_days'),
            ]);
        }

        $level = $mandate::LEVEL;
        $this->assertStartAcceptable($level, $startedAt, 'started_at');

        // Le renouvellement coexiste avec le mandat qu'il renouvelle — et avec lui seul : ni une autre
        // demande en attente, ni un autre mandat en cours à ce niveau (un renouvellement déjà validé).
        $other = $mandate::query()
            ->where('character_id', $mandate->character_id)
            ->whereKeyNot($mandate->id)
            ->where(fn ($query) => $query->where('status', Mandate::PENDING)
                ->orWhere(fn ($approved) => $approved->where('status', Mandate::APPROVED)
                    ->where('holds_until', '>', Carbon::now())))
            ->exists();
        if ($other) {
            throw $this->refuse('mandate', 'mandates.api.already_holding');
        }

        $attributes = [
            'character_id' => $mandate->character_id,
            'status' => Mandate::PENDING,
            'declared_started_at' => $startedAt,
            'started_at' => $startedAt,
            'announcement_url' => $announcementUrl,
            'renews_id' => $mandate->id,
        ];

        return $mandate instanceof CouncilMandate
            ? CouncilMandate::create($attributes + ['province_id' => $mandate->province_id])
            : MayorMandate::create($attributes + ['city_id' => $mandate->city_id]);
    }

    /** Annuler SA demande en attente. Rien d'autre ne se supprime côté joueur. */
    public function cancel(Mandate $mandate): void
    {
        if ($mandate->status !== Mandate::PENDING) {
            throw $this->refuse('mandate', 'mandates.api.not_pending');
        }

        $mandate->delete();
    }

    /**
     * « Déclarer mon poste » (Q8). La PERTE du poste actuel prend effet à l'envoi, sans preuve : le
     * joueur parle contre son intérêt. Le GAIN attend la validation de l'admin.
     */
    public function declareOffice(CouncilMandate $mandate, ?string $officeKey): CouncilMandate
    {
        if ($mandate->status !== Mandate::APPROVED || ! $mandate->isInOffice()) {
            throw $this->refuse('mandate', 'mandates.api.office_needs_running_mandate');
        }

        $current = $mandate->currentPeriod();
        if (($current?->office?->key) === $officeKey && ! $mandate->hasOfficeRequest()) {
            throw $this->refuse('council_office_key', 'mandates.api.same_office');
        }

        DB::transaction(function () use ($mandate, $current, $officeKey) {
            if ($current !== null) {
                $this->closePeriod($current, Carbon::now(), 'declare_par_le_joueur');
            }

            $mandate->update([
                'pending_office_id' => $officeKey ? CouncilOffice::where('key', $officeKey)->value('id') : null,
                'office_requested_at' => Carbon::now(),
            ]);
        });

        return $mandate->refresh();
    }

    // ─── Gestes de l'administrateur : décisions sur une demande ───────────────────────────

    /**
     * Valider une demande.
     *
     * @param  array{started_at?: ?string, in_office_from?: ?string, replace_holder?: bool, note?: ?string}  $options
     */
    public function approve(Mandate $mandate, array $options = []): Mandate
    {
        if ($mandate->status !== Mandate::PENDING) {
            throw $this->refuse('mandate', 'mandates.admin.errors.not_pending');
        }

        $level = $mandate::LEVEL;
        $startedAt = $options['started_at'] ?? $mandate->started_at->format('Y-m-d');
        $this->assertStartAcceptable($level, $startedAt, 'started_at', admin: true);

        $validUntil = MandateCalendar::validUntil($level, $startedAt);
        $holdsUntil = MandateCalendar::nominalHoldsUntil($level, $validUntil);
        $inOfficeFrom = $this->inOfficeFromOnApproval($mandate, $startedAt, $options['in_office_from'] ?? null, $holdsUntil);

        DB::transaction(function () use ($mandate, $startedAt, $validUntil, $holdsUntil, $inOfficeFrom, $options) {
            if (($options['replace_holder'] ?? false) && $mandate instanceof MayorMandate && $inOfficeFrom !== null) {
                $this->replaceMayor($mandate, $inOfficeFrom);
            }

            if ($startedAt !== $mandate->started_at->format('Y-m-d')) {
                $mandate->appendNote(__('mandates.admin.trace.start_corrected', [
                    'from' => $mandate->started_at->format('d/m/Y'),
                    'to' => Carbon::parse($startedAt)->format('d/m/Y'),
                ]));
            }
            if (! empty($options['note'])) {
                $mandate->appendNote($options['note']);
            }

            $mandate->fill([
                'status' => Mandate::APPROVED,
                'started_at' => $startedAt,
                'valid_until' => $validUntil,
                'in_office_from' => $inOfficeFrom,
                'processed_at' => Carbon::now(),
            ]);
            $this->setHoldsUntil($mandate, $holdsUntil, 'nominal');

            $titleDropped = false;
            if ($mandate instanceof CouncilMandate && $mandate->hasOfficeRequest()) {
                if ($mandate->isInOffice()) {
                    // Inscription en milieu de mandat : le titre est réel, il est validé avec le
                    // mandat, et le transfert s'applique (Q15).
                    if ($mandate->pending_office_id !== null) {
                        $this->grantOffice($mandate, $mandate->pending_office_id, notify: false);
                    }
                } else {
                    // Q15-C : un titre déclaré avant l'entrée en fonction est PROBABLEMENT FAUX
                    // (celui du conseil sortant, ou une erreur) — pas seulement prématuré. Il est
                    // abandonné, sans transfert, et NE SUBSISTE NULLE PART. Ne pas le « garder en
                    // attente » pour épargner une démarche au joueur : Greg le validerait après
                    // l'élection du dirigeant sans le revérifier (tour 07).
                    $titleDropped = $mandate->pending_office_id !== null;
                }
                $mandate->forceFill(['pending_office_id' => null, 'office_requested_at' => null])->save();
            }

            // Le mandat renouvelé est vérifié d'office : la question « est-il encore là » a sa
            // réponse (file de vérification, tour 05 ajout 2).
            if ($mandate->renews_id !== null) {
                $mandate::query()->whereKey($mandate->renews_id)->whereNull('verified_at')
                    ->update(['verified_at' => Carbon::now()]);
            }

            $this->queue($mandate, new MandateDecision('approved', $mandate, [
                'date' => $validUntil,
                'elected' => $inOfficeFrom === null || $inOfficeFrom->isFuture(),
                'title_dropped' => $titleDropped,
            ]));
        });

        $this->flush();

        return $mandate->refresh();
    }

    /**
     * Validation groupée, RÉSERVÉE AUX RENOUVELLEMENTS : la vérification d'une première demande
     * ne doit pas devenir un clic réflexe (§5quinquies). Tout ou rien.
     *
     * @param  array<int, Mandate>  $mandates
     */
    public function approveRenewals(array $mandates): int
    {
        // Tout est vérifié AVANT la première validation : un lot ne s'arrête pas au milieu.
        foreach ($mandates as $mandate) {
            if ($mandate->renews_id === null || $mandate->status !== Mandate::PENDING) {
                throw $this->refuse('mandates', 'mandates.admin.batch_only_renewals');
            }
            $this->assertStartAcceptable($mandate::LEVEL, $mandate->started_at->format('Y-m-d'), 'mandates', admin: true);
        }

        foreach ($mandates as $mandate) {
            $this->approve($mandate);
        }

        return count($mandates);
    }

    public function reject(Mandate $mandate, string $reason, ?string $message, ?string $locale, ?string $note = null): Mandate
    {
        if ($mandate->status !== Mandate::PENDING) {
            throw $this->refuse('mandate', 'mandates.admin.errors.not_pending');
        }
        $this->assertReason($reason, config('mandates.reject_reasons'), $message);

        if ($note) {
            $mandate->appendNote($note);
        }
        $mandate->fill([
            'status' => Mandate::REJECTED,
            'processed_at' => Carbon::now(),
            'decision_reason' => $reason,
            'decision_message' => $message ?: null,
            'decision_message_locale' => $message ? ($locale ?: 'fr') : null,
        ]);
        if ($mandate instanceof CouncilMandate) {
            $mandate->pending_office_id = null;
            $mandate->office_requested_at = null;
        }
        $mandate->save();

        $this->queue($mandate, new MandateDecision('rejected', $mandate, [
            'reason' => $reason, 'message' => $message, 'locale' => $locale,
        ]));
        $this->flush();

        return $mandate;
    }

    /**
     * Corriger le MOTIF d'un refus ou d'une révocation (tours 15 et 17). Ne change JAMAIS la
     * décision : ni statut, ni date, ni période. Pour changer une décision, il y a une nouvelle
     * demande (refus) ou l'annulation de révocation dans sa fenêtre (Q27).
     */
    public function correctDecision(Mandate $mandate, string $reason, ?string $message, ?string $locale): Mandate
    {
        $reasons = match ($mandate->status) {
            Mandate::REJECTED => config('mandates.reject_reasons'),
            Mandate::REVOKED => config('mandates.revoke_reasons'),
            default => throw $this->refuse('mandate', 'mandates.admin.errors.no_decision'),
        };
        $this->assertReason($reason, $reasons, $message);

        $oldReason = $mandate->decision_reason;
        $mandate->appendNote(__('mandates.admin.trace.decision_corrected', [
            'from' => $oldReason, 'to' => $reason,
        ]));
        $mandate->forceFill([
            'decision_reason' => $reason,
            'decision_message' => $message ?: null,
            'decision_message_locale' => $message ? ($locale ?: 'fr') : null,
        ])->save();

        $this->queue($mandate, new MandateDecision('reason_corrected', $mandate, [
            'date' => $mandate->status === Mandate::REVOKED ? $mandate->revoked_at : $mandate->processed_at,
            'old_reason' => $oldReason,
            'reason' => $reason,
            'message' => $message,
            'locale' => $locale,
            'can_reapply' => $mandate->status === Mandate::REJECTED,
        ]));
        $this->flush();

        return $mandate;
    }

    // ─── Gestes de l'administrateur : fin et retour d'un mandat ───────────────────────────

    /**
     * Révoquer, AVEC OU SANS successeur (mise à jour du 03/10, point 2). La date d'effet est
     * saisie : maintenant par défaut, jamais dans le futur, jamais avant l'entrée en fonction (Q1).
     */
    public function revoke(Mandate $mandate, string $reason, ?string $effectiveAt, ?string $message, ?string $locale, ?string $note = null): Mandate
    {
        $this->assertReason($reason, config('mandates.revoke_reasons'), $message);
        $effective = $effectiveAt ? MandateCalendar::startOfGameDay($effectiveAt) : Carbon::now();

        DB::transaction(function () use ($mandate, $reason, $effective, $message, $locale, $note) {
            $this->revokeAt($mandate, $effective, $reason, $message, $locale, $note);
        });
        $this->flush();

        return $mandate->refresh();
    }

    /**
     * Annuler une révocation — seulement pendant la période nominale du mandat (Q27-C). La fin
     * effective revient à sa valeur nominale ; une prolongation accordée avant la révocation n'est
     * PAS restaurée (Q25) : Greg la reprend sciemment, la note lui montre qu'elle avait existé. La
     * période de poste fermée par la révocation n'est JAMAIS rouverte (Q23) : le joueur redéclare.
     */
    public function unrevoke(Mandate $mandate, ?string $note = null): Mandate
    {
        if ($mandate->status !== Mandate::REVOKED) {
            throw $this->refuse('mandate', 'mandates.admin.errors.not_revoked');
        }
        if (Carbon::now()->greaterThanOrEqualTo($mandate->valid_until)) {
            throw $this->refuse('mandate', 'mandates.admin.errors.unrevoke_after_term');
        }

        // Q24 : deux vérifications distinctes selon le niveau, et le message NOMME le mandat bloquant.
        $blocking = $mandate instanceof MayorMandate
            ? MayorMandate::query()->running()->where('city_id', $mandate->city_id)->whereKeyNot($mandate->id)->with('character')->first()
            : CouncilMandate::query()->running()->where('character_id', $mandate->character_id)->whereKeyNot($mandate->id)->with('character')->first();
        if ($blocking !== null) {
            throw $this->refuse('mandate', 'mandates.admin.errors.unrevoke_blocked', [
                'pseudo' => $blocking->character?->pseudo,
                'from' => MandateLabels::date($blocking->in_office_from ?? MandateCalendar::startOfGameDay($blocking->started_at)),
            ]);
        }

        DB::transaction(function () use ($mandate, $note) {
            $mandate->appendNote(__('mandates.admin.trace.unrevoked', ['reason' => $mandate->decision_reason]));
            if ($note) {
                $mandate->appendNote($note);
            }
            $mandate->forceFill([
                'status' => Mandate::APPROVED,
                'revoked_at' => null,
                'decision_reason' => null,
                'decision_message' => null,
                'decision_message_locale' => null,
            ]);
            $this->setHoldsUntil($mandate, MandateCalendar::nominalHoldsUntil($mandate::LEVEL, $mandate->valid_until), 'nominal');
        });

        return $mandate->refresh();
    }

    // ─── Gestes de l'administrateur : corrections de dates ────────────────────────────────

    /**
     * Corriger la date de début retenue (tour 11, geste 1). declared_started_at n'est jamais
     * touché. La fin effective ne suit que si elle est encore NOMINALE (Q25-C) : une prolongation,
     * une passation ou une révocation sont des faits constatés, pas des conséquences de la date.
     *
     * @return bool vrai si la fin effective a suivi la correction
     */
    public function correctStart(Mandate $mandate, string $startedAt): bool
    {
        if ($mandate->status !== Mandate::APPROVED) {
            throw $this->refuse('started_at', 'mandates.admin.errors.not_approved');
        }

        $level = $mandate::LEVEL;
        $this->assertStartAcceptable($level, $startedAt, 'started_at', admin: true);

        $validUntil = MandateCalendar::validUntil($level, $startedAt);
        $followsEnd = $mandate->holds_until_set_by === 'nominal';

        if ($mandate instanceof CouncilMandate && $mandate->in_office_from !== null
            && $mandate->in_office_from->lessThan(MandateCalendar::startOfGameDay($startedAt))) {
            throw $this->refuse('started_at', 'mandates.admin.errors.in_office_before_start');
        }

        DB::transaction(function () use ($mandate, $startedAt, $validUntil, $followsEnd, $level) {
            $mandate->appendNote(__('mandates.admin.trace.start_corrected', [
                'from' => $mandate->started_at->format('d/m/Y'),
                'to' => Carbon::parse($startedAt)->format('d/m/Y'),
            ]));
            $mandate->fill(['started_at' => $startedAt, 'valid_until' => $validUntil]);

            // Maire : en fonction dès sa date de début, donc in_office_from la suit.
            if ($mandate instanceof MayorMandate) {
                $mandate->in_office_from = MandateCalendar::startOfGameDay($startedAt);
            }

            if ($followsEnd) {
                $this->setHoldsUntil($mandate, MandateCalendar::nominalHoldsUntil($level, $validUntil), 'nominal');
            } else {
                $mandate->save();
            }
        });

        return $followsEnd;
    }

    /**
     * Corriger l'entrée en fonction d'un conseiller (tour 11, geste 2). Vers une date
     * POSTÉRIEURE au début d'une période de poste : refusé, la période est un fait constaté (Q28).
     */
    public function correctInOffice(CouncilMandate $mandate, string $date): CouncilMandate
    {
        if ($mandate->status !== Mandate::APPROVED) {
            throw $this->refuse('in_office_from', 'mandates.admin.errors.not_approved');
        }

        $inOfficeFrom = MandateCalendar::startOfGameDay($date);
        $this->assertInOfficeFrom($mandate, $inOfficeFrom, $mandate->holds_until);

        $earlier = $mandate->officePeriods()->with('office')->where('started_at', '<', $inOfficeFrom)->orderBy('started_at')->first();
        if ($earlier !== null) {
            throw $this->refuse('in_office_from', 'mandates.admin.errors.period_before_in_office', [
                'office' => MandateLabels::office($earlier->office?->key, 'fr'),
                'date' => MandateLabels::date($earlier->started_at),
            ]);
        }

        $mandate->appendNote(__('mandates.admin.trace.in_office_corrected', [
            'from' => MandateLabels::date($mandate->in_office_from) ?: '—',
            'to' => MandateLabels::date($inOfficeFrom),
        ]));
        $mandate->forceFill(['in_office_from' => $inOfficeFrom])->save();

        return $mandate;
    }

    // ─── Gestes de l'administrateur : province ────────────────────────────────────────────

    /** Prolonger de 2 jours tous les conseillers EN PROLONGATION de la province, note obligatoire. */
    public function extendProvince(Province $province, string $note): int
    {
        $now = Carbon::now();
        $extended = CouncilMandate::query()->inOfficeNow()
            ->where('status', Mandate::APPROVED)
            ->where('province_id', $province->id)
            ->where('valid_until', '<=', $now)
            ->get();

        if ($extended->isEmpty()) {
            throw $this->refuse('province', 'mandates.admin.errors.nothing_to_extend');
        }

        DB::transaction(function () use ($extended, $note) {
            foreach ($extended as $mandate) {
                $mandate->appendNote(__('mandates.admin.trace.extended', ['note' => $note]));
                $this->setHoldsUntil($mandate,
                    MandateCalendar::addGameDays($mandate->holds_until, config('mandates.council_extension_days')),
                    'extension');
            }
        });

        return $extended->count();
    }

    /**
     * Passation à l'élection du dirigeant, date L (00:00 heure du jeu, Q11). En un geste : les
     * sortants cochés sortent à L, les entrants cochés entrent à L SANS POSTE (Q14), et les postes
     * des mandats qui CONTINUENT sont vidés (fait 2). Ni trou ni chevauchement d'appartenance ; les
     * postes, eux, restent vides de L jusqu'aux nominations — état légitime (tour 05).
     *
     * @param  array<int, int>  $outgoingIds
     * @param  array<int, int>  $incomingIds
     */
    public function handover(Province $province, string $date, array $outgoingIds, array $incomingIds): void
    {
        if ($outgoingIds === [] && $incomingIds === []) {
            throw $this->refuse('handover', 'mandates.admin.errors.handover_empty');
        }

        $leaderElectedAt = MandateCalendar::startOfGameDay($date);
        if ($leaderElectedAt->isFuture()) {
            throw $this->refuse('date', 'mandates.admin.errors.date_in_future');
        }

        $outgoing = CouncilMandate::query()->whereIn('id', $outgoingIds)->with('character')->get();
        $incoming = CouncilMandate::query()->whereIn('id', $incomingIds)->with('character')->get();

        foreach ($outgoing as $mandate) {
            $this->assertHandoverOutgoing($mandate, $province, $leaderElectedAt);
        }
        foreach ($incoming as $mandate) {
            $this->assertHandoverIncoming($mandate, $province, $leaderElectedAt);
        }

        DB::transaction(function () use ($province, $leaderElectedAt, $outgoing, $incoming) {
            foreach ($outgoing as $mandate) {
                $this->setHoldsUntil($mandate, $leaderElectedAt, 'handover');
                $this->queue($mandate, new MandateDecision('handover_out', $mandate, ['date' => $leaderElectedAt]));
            }
            foreach ($incoming as $mandate) {
                $mandate->forceFill(['in_office_from' => $leaderElectedAt])->save();
                $this->queue($mandate, new MandateDecision('handover_in', $mandate, ['date' => $leaderElectedAt]));
            }

            $this->clearContinuingOffices($province, $leaderElectedAt, $outgoing->pluck('id')->all());
        });

        $this->flush();
    }

    /**
     * « Nouveau dirigeant élu » SANS passation (démission du dirigeant, fait 2) : vide les postes
     * des mandats qui continuent, ne touche aucun mandat.
     */
    public function clearOffices(Province $province, string $date): int
    {
        $leaderElectedAt = MandateCalendar::startOfGameDay($date);
        if ($leaderElectedAt->isFuture()) {
            throw $this->refuse('date', 'mandates.admin.errors.date_in_future');
        }

        $count = DB::transaction(fn () => $this->clearContinuingOffices($province, $leaderElectedAt, []));
        $this->flush();

        return $count;
    }

    // ─── Gestes de l'administrateur : postes ──────────────────────────────────────────────

    /** Valider une déclaration de poste du joueur : le poste est gagné, et transféré s'il était détenu. */
    public function approveOfficeRequest(CouncilMandate $mandate): CouncilMandate
    {
        if (! $mandate->hasOfficeRequest() || $mandate->status !== Mandate::APPROVED) {
            throw $this->refuse('mandate', 'mandates.admin.errors.no_office_request');
        }
        if (! $mandate->isInOffice()) {
            throw $this->refuse('mandate', 'mandates.admin.errors.office_not_in_office');
        }

        DB::transaction(function () use ($mandate) {
            if ($mandate->pending_office_id !== null) {
                $this->grantOffice($mandate, $mandate->pending_office_id, notify: true);
            } else {
                // Déclaration « devenir sans poste » : la perte a déjà eu lieu à l'envoi.
                $this->queue($mandate, new MandateDecision('office_granted', $mandate, ['office' => null]));
            }
            $mandate->forceFill(['pending_office_id' => null, 'office_requested_at' => null])->save();
        });
        $this->flush();

        return $mandate->refresh();
    }

    /** Refuser une déclaration de poste : rien ne se rouvre, la perte à l'envoi reste acquise. */
    public function rejectOfficeRequest(CouncilMandate $mandate, string $reason, ?string $message, ?string $locale): CouncilMandate
    {
        if (! $mandate->hasOfficeRequest()) {
            throw $this->refuse('mandate', 'mandates.admin.errors.no_office_request');
        }
        $this->assertReason($reason, config('mandates.reject_reasons'), $message);

        $mandate->appendNote(__('mandates.admin.trace.office_request_rejected', ['reason' => $reason]));
        $mandate->forceFill(['pending_office_id' => null, 'office_requested_at' => null])->save();

        $this->queue($mandate, new MandateDecision('office_rejected', $mandate, [
            'reason' => $reason, 'message' => $message, 'locale' => $locale,
        ]));
        $this->flush();

        return $mandate;
    }

    /**
     * Correction directe par l'admin, sans demande du joueur (tour 05, ajout 3) : attribuer,
     * transférer ou retirer. Seulement sur un mandat EN FONCTION (tour 07).
     */
    public function setOffice(CouncilMandate $mandate, ?string $officeKey, ?string $reason = null, ?string $message = null, ?string $locale = null): CouncilMandate
    {
        if ($mandate->status !== Mandate::APPROVED || ! $mandate->isInOffice()) {
            throw $this->refuse('mandate', 'mandates.admin.errors.office_not_in_office');
        }

        DB::transaction(function () use ($mandate, $officeKey, $reason, $message, $locale) {
            if ($officeKey !== null) {
                $this->grantOffice($mandate, CouncilOffice::where('key', $officeKey)->value('id'), notify: true);
            } else {
                // Retrait SANS successeur : personne ne gagne, donc un email au perdant (Q16).
                $this->assertReason((string) $reason, config('mandates.office_remove_reasons'), $message);
                $current = $mandate->currentPeriod();
                if ($current === null) {
                    throw $this->refuse('council_office_key', 'mandates.admin.errors.no_office');
                }
                $this->closePeriod($current, Carbon::now(), $reason);
                $this->queue($mandate, new MandateDecision('office_removed', $mandate, [
                    'reason' => $reason, 'message' => $message, 'locale' => $locale,
                ]));
            }
            $mandate->forceFill(['pending_office_id' => null, 'office_requested_at' => null])->save();
        });
        $this->flush();

        return $mandate->refresh();
    }

    /** File de vérification : confirmer que le titulaire d'un mandat terminé n'exerce plus. */
    public function verify(Mandate $mandate): Mandate
    {
        $mandate->forceFill(['verified_at' => Carbon::now()])->save();

        return $mandate;
    }

    // ─── Mécanique interne ────────────────────────────────────────────────────────────────

    /**
     * SEULE écriture de holds_until (Q19, Q25). Dans le même appel : holds_until_set_by, et
     * l'ended_at de la période de poste EN COURS qui suit encore la fin du mandat (end_reason =
     * fin_du_mandat, pas encore terminée). Une période déjà terminée — par un événement de poste,
     * ou parce que la fin du mandat est passée — ne bouge jamais : c'est ce qui empêche une
     * annulation de révocation de la rouvrir (Q23).
     */
    public function setHoldsUntil(Mandate $mandate, CarbonInterface $holdsUntil, string $setBy): void
    {
        $mandate->forceFill([
            'holds_until' => $holdsUntil,
            'holds_until_set_by' => $setBy,
        ])->save();

        if ($mandate instanceof CouncilMandate) {
            CouncilOfficePeriod::query()
                ->where('council_mandate_id', $mandate->id)
                ->where('end_reason', 'fin_du_mandat')
                ->where('ended_at', '>', Carbon::now())
                ->update(['ended_at' => $holdsUntil]);
        }
    }

    /** Ferme une période à un instant donné, avec sa cause. Jamais avant son début (Q28). */
    private function closePeriod(CouncilOfficePeriod $period, CarbonInterface $at, string $reason): void
    {
        $period->forceFill(['ended_at' => $at, 'end_reason' => $reason])->save();
    }

    /**
     * Le mandat gagne un poste MAINTENANT. Exclusif dans la province (tour 05, ajout 3) : la période
     * en vigueur de tout autre détenteur se ferme à la même seconde avec `reattribue` — sans email
     * pour lui, l'événement est notifié au gagnant. Le transfert ne touche que des mandats EN
     * FONCTION (corollaire du tour 06) : un sortant face à un entrant élu garde son poste.
     */
    private function grantOffice(CouncilMandate $mandate, int $officeId, bool $notify): void
    {
        $now = Carbon::now();

        $holders = CouncilOfficePeriod::query()
            ->where('council_office_id', $officeId)
            ->where('started_at', '<=', $now)
            ->where('ended_at', '>', $now)
            ->whereHas('mandate', fn ($query) => $query->where('province_id', $mandate->province_id))
            ->get();
        foreach ($holders as $period) {
            if ($period->council_mandate_id === $mandate->id) {
                continue;
            }
            $this->closePeriod($period, $now, 'reattribue');
            $holder = $period->mandate;
            $holder->appendNote(__('mandates.admin.trace.office_reassigned', [
                'office' => MandateLabels::office(CouncilOffice::find($officeId)?->key, 'fr'),
                'pseudo' => $mandate->character?->pseudo,
            ]));
            $holder->save();
        }

        // Son propre poste précédent, s'il en avait un autre, se ferme aussi.
        $own = $mandate->currentPeriod();
        if ($own !== null && $own->council_office_id === $officeId) {
            return;
        }
        if ($own !== null) {
            $this->closePeriod($own, $now, 'reattribue');
        }

        // La période naît FERMÉE à la fin du mandat (Q19-C), avec end_reason = fin_du_mandat posé dès
        // la naissance : c'est la cause la plus courante, l'historique ne doit jamais la laisser vide.
        CouncilOfficePeriod::create([
            'council_mandate_id' => $mandate->id,
            'council_office_id' => $officeId,
            'started_at' => $now,
            'ended_at' => $mandate->holds_until,
            'end_reason' => 'fin_du_mandat',
        ]);

        if ($notify) {
            $this->queue($mandate, new MandateDecision('office_granted', $mandate, [
                'office' => CouncilOffice::find($officeId)?->key,
            ]));
        }
    }

    /**
     * Vide les postes de la province à l'élection d'un dirigeant (fait 2) — UNIQUEMENT ceux des
     * mandats qui CONTINUENT (Q26) : les mandats qui se terminent dans le même geste ferment leur
     * période par la fin de mandat. Ne ferme que les périodes commencées AVANT L : une nomination
     * postérieure est le fait du nouveau dirigeant. Un email par titulaire vidé, puisque personne
     * ne gagne et que son mandat continue (Q22).
     *
     * @param  array<int, int>  $endingMandateIds
     */
    private function clearContinuingOffices(Province $province, CarbonInterface $leaderElectedAt, array $endingMandateIds): int
    {
        $now = Carbon::now();
        $periods = CouncilOfficePeriod::query()
            ->where('started_at', '<', $leaderElectedAt)
            ->where('ended_at', '>', $now)
            ->whereNotIn('council_mandate_id', $endingMandateIds)
            ->whereHas('mandate', fn ($query) => $query->where('province_id', $province->id)->where('status', Mandate::APPROVED))
            ->with('mandate.character')
            ->get();

        foreach ($periods as $period) {
            $this->closePeriod($period, max($leaderElectedAt, $period->started_at), 'retire_par_le_dirigeant');
            $this->queue($period->mandate, new MandateDecision('office_removed', $period->mandate, [
                'reason' => 'retire_par_le_dirigeant',
            ]));
        }

        return $periods->count();
    }

    /** Révocation interne, partagée par revoke() et le remplacement d'un maire (Q2). */
    private function revokeAt(Mandate $mandate, CarbonInterface $effective, string $reason, ?string $message, ?string $locale, ?string $note): void
    {
        if (! $mandate->isRunning()) {
            throw $this->refuse('mandate', 'mandates.admin.errors.not_running');
        }
        if ($effective->isFuture()) {
            throw $this->refuse('effective_at', 'mandates.admin.errors.effective_in_future');
        }
        if ($mandate->in_office_from !== null && $effective->lessThan($mandate->in_office_from)) {
            throw $this->refuse('effective_at', 'mandates.admin.errors.effective_before_office');
        }
        if ($mandate instanceof CouncilMandate) {
            // Même principe que Q28 : une période déjà commencée ne se réécrit pas en arrière.
            $later = $mandate->officePeriods()->with('office')->where('started_at', '>', $effective)->first();
            if ($later !== null) {
                throw $this->refuse('effective_at', 'mandates.admin.errors.period_after_effect', [
                    'office' => MandateLabels::office($later->office?->key, 'fr'),
                    'date' => MandateLabels::date($later->started_at),
                ]);
            }
        }

        if ($note) {
            $mandate->appendNote($note);
        }
        $mandate->forceFill([
            'status' => Mandate::REVOKED,
            'revoked_at' => Carbon::now(),
            'decision_reason' => $reason,
            'decision_message' => $message ?: null,
            'decision_message_locale' => $message ? ($locale ?: 'fr') : null,
        ]);
        $this->setHoldsUntil($mandate, $effective->lessThan($mandate->holds_until) ? $effective : $mandate->holds_until, 'revocation');

        $this->queue($mandate, new MandateDecision('revoked', $mandate, [
            'date' => $effective, 'reason' => $reason, 'message' => $message, 'locale' => $locale,
        ]));
    }

    /**
     * Remplacer le maire en place (révolte : le successeur prend effet immédiatement). Le sortant
     * est clos à l'ENTRÉE EN FONCTION DU SUCCESSEUR, pas à l'heure de l'approbation : ni trou ni
     * chevauchement (Q2). Si le successeur déclare une date antérieure à celle du sortant, refus.
     */
    private function replaceMayor(MayorMandate $successor, CarbonInterface $inOfficeFrom): void
    {
        $holders = MayorMandate::query()->running()
            ->where('city_id', $successor->city_id)
            ->whereKeyNot($successor->id)
            ->with('character')
            ->get();

        foreach ($holders as $holder) {
            if ($holder->in_office_from !== null && $inOfficeFrom->lessThan($holder->in_office_from)) {
                throw $this->refuse('replace_holder', 'mandates.admin.errors.replace_before_holder', [
                    'pseudo' => $holder->character?->pseudo,
                ]);
            }
            $this->revokeAt($holder, $inOfficeFrom, 'remplace', null, null, null);
        }
    }

    /**
     * Entrée en fonction à l'approbation individuelle (Q18). Maire : son début. Conseiller : JAMAIS
     * prérempli avec la date du dirigeant — NULL (élu, sans autorité) pour un entrant ordinaire,
     * ou la date saisie par Greg pour un remplaçant en cours de mandat. Seul le geste de province
     * « dirigeant élu le » pose L sur le conseil entrant.
     */
    private function inOfficeFromOnApproval(Mandate $mandate, string $startedAt, ?string $date, CarbonInterface $holdsUntil): ?Carbon
    {
        if ($mandate instanceof MayorMandate) {
            return MandateCalendar::startOfGameDay($startedAt);
        }
        if ($date === null || $date === '') {
            return null;
        }

        $inOfficeFrom = MandateCalendar::startOfGameDay($date);
        $mandate->started_at = Carbon::parse($startedAt);
        $this->assertInOfficeFrom($mandate, $inOfficeFrom, $holdsUntil);

        return $inOfficeFrom;
    }

    private function assertInOfficeFrom(Mandate $mandate, CarbonInterface $inOfficeFrom, ?CarbonInterface $holdsUntil): void
    {
        if ($inOfficeFrom->isFuture()) {
            throw $this->refuse('in_office_from', 'mandates.admin.errors.date_in_future');
        }
        if ($inOfficeFrom->lessThan(MandateCalendar::startOfGameDay($mandate->started_at))) {
            throw $this->refuse('in_office_from', 'mandates.admin.errors.in_office_before_start');
        }
        if ($holdsUntil !== null && $inOfficeFrom->greaterThanOrEqualTo($holdsUntil)) {
            throw $this->refuse('in_office_from', 'mandates.admin.errors.dead_born');
        }
    }

    /**
     * Une date de début déclarée fixe la propre fenêtre d'autorité du joueur : vérifiée côté
     * serveur. Jamais dans le futur (le mandat s'auto-prolongerait) ; jamais si le mandat serait
     * déjà terminé, avec un message qui nomme la cause (« mort-né »).
     */
    private function assertStartAcceptable(string $level, string $startedAt, string $field, bool $admin = false): void
    {
        if ($startedAt > MandateCalendar::today()) {
            throw $this->refuse($field, $admin ? 'mandates.admin.errors.started_in_future' : 'mandates.api.started_in_future');
        }

        $holdsUntil = MandateCalendar::nominalHoldsUntil($level, MandateCalendar::validUntil($level, $startedAt));
        if ($holdsUntil->lessThanOrEqualTo(Carbon::now())) {
            throw $this->refuse($field, $admin ? 'mandates.admin.errors.dead_born' : 'mandates.api.dead_born');
        }
    }

    /** Un mandat par niveau au plus : ni demande en attente, ni mandat en cours (élu compris). */
    private function assertNoRunningAtLevel(Character $character, string $level): void
    {
        if ($this->blockingMandate($character, $level) !== null) {
            throw $this->refuse('level', 'mandates.api.already_holding');
        }
    }

    /** @param  array<int, string>  $allowed */
    private function assertReason(string $reason, array $allowed, ?string $message): void
    {
        if (! in_array($reason, $allowed, true)) {
            throw $this->refuse('reason', 'mandates.admin.errors.invalid_reason');
        }
        if ($reason === 'autre' && trim((string) $message) === '') {
            throw $this->refuse('message', 'mandates.admin.errors.comment_required');
        }
    }

    private function assertHandoverOutgoing(CouncilMandate $mandate, Province $province, CarbonInterface $leaderElectedAt): void
    {
        $belongs = $mandate->province_id === $province->id && $mandate->status === Mandate::APPROVED
            && $mandate->in_office_from !== null && $mandate->in_office_from->lessThanOrEqualTo($leaderElectedAt)
            && $mandate->holds_until->greaterThan($leaderElectedAt);
        if (! $belongs) {
            throw $this->refuse('outgoing', 'mandates.admin.errors.handover_wrong_mandate');
        }
        if ($leaderElectedAt->lessThan($mandate->valid_until)) {
            throw $this->refuse('date', 'mandates.admin.errors.handover_before_outgoing', [
                'pseudo' => $mandate->character?->pseudo,
            ]);
        }
        $later = $mandate->officePeriods()->with('office')->where('started_at', '>', $leaderElectedAt)->first();
        if ($later !== null) {
            throw $this->refuse('date', 'mandates.admin.errors.period_after_effect', [
                'office' => MandateLabels::office($later->office?->key, 'fr'),
                'date' => MandateLabels::date($later->started_at),
            ]);
        }
    }

    private function assertHandoverIncoming(CouncilMandate $mandate, Province $province, CarbonInterface $leaderElectedAt): void
    {
        $belongs = $mandate->province_id === $province->id && $mandate->status === Mandate::APPROVED
            && $mandate->in_office_from === null && $mandate->holds_until->greaterThan($leaderElectedAt);
        if (! $belongs) {
            throw $this->refuse('incoming', 'mandates.admin.errors.handover_wrong_mandate');
        }
        if ($leaderElectedAt->lessThan(MandateCalendar::startOfGameDay($mandate->started_at))) {
            throw $this->refuse('date', 'mandates.admin.errors.handover_before_incoming', [
                'pseudo' => $mandate->character?->pseudo,
            ]);
        }
    }

    /**
     * Construit un refus métier. Le CODE se déduit de la clé de traduction — tout refus en porte donc
     * un, par construction (fil mandats-lot2, Q1/Q8) :
     *   mandates.api.<code>           → `<code>`        refus que le joueur peut recevoir (FR + EN)
     *   mandates.admin.errors.<code>  → `admin.<code>`  refus d'administration (FR seul)
     * ⚠️ Le préfixe `admin.` est une indication pour les humains, SANS valeur mécanique : c'est la
     * couche API (bootstrap/app.php) qui refuse d'en émettre un, test à l'appui.
     *
     * @param  array<string, mixed>  $params
     */
    private function refuse(string $field, string $key, array $params = []): MandateRefusal
    {
        return MandateRefusal::make($field, $key, $params);
    }

    private function queue(Mandate $mandate, MandateDecision $notification): void
    {
        $this->outbox[] = [$mandate, $notification];
    }

    private function flush(): void
    {
        $outbox = $this->outbox;
        $this->outbox = [];

        foreach ($outbox as [$mandate, $notification]) {
            $mandate->loadMissing('character.user');
            $mandate->character?->user?->notify($notification);
        }
    }
}
