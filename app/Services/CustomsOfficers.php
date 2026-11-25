<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\City;
use App\Models\CustomsOfficer;
use App\Models\Province;
use App\Models\ProvinceSecuritySettings;
use Illuminate\Support\Carbon;

/**
 * SEUL écrivain des douaniers (module Sécurité, Greg, 21/11/2026).
 *
 * - Le douanier DEMANDE (opt-in) ; il n'est jamais nommé d'office. Le trio valide, en cascade (MandateAuthority::customsDeciderLevel) ;
 *   l'administrateur est le niveau 3, hors de MandateAuthority.
 * - Un seul rôle ou demande en cours par personnage (`active`).
 * - Il peut révoquer son propre accès à tout moment.
 * - Inactivité : seule la saisie d'un bilan remet le compteur à zéro (recordReport). Durée réglée par province.
 *   Avec un trio connu : signalement seulement, ils décident. Sans trio : avis à 7 jours puis révocation automatique ;
 *   le joueur peut refaire une demande ensuite.
 *
 * ⚠️ Aucune décision n'est prise ici sur « qui a le droit de lire ou d'écrire un bilan » : ce sera MandateAuthority (PR 3).
 */
class CustomsOfficers
{
    public function __construct(private readonly MandateAuthority $authority) {}

    public function request(Character $character, City $city): CustomsOfficer
    {
        if (! $character->is_validated) {
            throw MandateRefusal::make('character', 'security.api.character_not_validated');
        }

        if (CustomsOfficer::query()->where('character_id', $character->id)->where('active', 1)->exists()) {
            throw MandateRefusal::make('character', 'security.api.already_active');
        }

        return CustomsOfficer::create([
            'character_id' => $character->id,
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'status' => CustomsOfficer::PENDING,
            'active' => 1,
        ]);
    }

    /** Valide ou refuse une demande, au niveau 1 ou 2 selon le poste de `$decider` dans la province de la demande. */
    public function decide(CustomsOfficer $officer, Character $decider, bool $approve): CustomsOfficer
    {
        $this->assertPending($officer);

        if ($decider->id === $officer->character_id) {
            throw MandateRefusal::make('officer', 'security.api.self_decision');
        }

        $level = $this->authority->customsDeciderLevel($decider, $officer->province);
        if ($level === null) {
            throw MandateRefusal::make('officer', 'security.api.not_authorized');
        }

        return $this->settle($officer, $approve, $decider, $level);
    }

    /** Niveau 3 : l'administrateur, sur demande. Sans personnage décideur. */
    public function decideAsAdmin(CustomsOfficer $officer, bool $approve): CustomsOfficer
    {
        $this->assertPending($officer);

        return $this->settle($officer, $approve, null, 3);
    }

    /** Le douanier retire sa propre demande (en attente : la ligne disparaît) ou son propre accès (en vigueur : trace gardée). */
    public function withdraw(Character $character): void
    {
        $officer = CustomsOfficer::query()->where('character_id', $character->id)->where('active', 1)->first();
        if (! $officer) {
            throw MandateRefusal::make('character', 'security.api.not_officer');
        }

        if ($officer->status === CustomsOfficer::PENDING) {
            $officer->delete();

            return;
        }

        $this->close($officer, CustomsOfficer::REASON_SELF, $character);
    }

    /** Révocation par le niveau 1 ou 2 (selon le poste de `$revoker`) ou, sans personnage, par l'administrateur. */
    public function revoke(CustomsOfficer $officer, ?Character $revoker): CustomsOfficer
    {
        if ($officer->status !== CustomsOfficer::APPROVED) {
            throw MandateRefusal::make('officer', 'security.api.not_officer');
        }

        if ($revoker === null) {
            return $this->close($officer, CustomsOfficer::REASON_ADMIN, null);
        }

        $level = $this->authority->customsDeciderLevel($revoker, $officer->province);
        if ($level === null) {
            throw MandateRefusal::make('officer', 'security.api.not_authorized');
        }

        return $this->close($officer, $level === 1 ? CustomsOfficer::REASON_PRIMARY : CustomsOfficer::REASON_FALLBACK, $revoker);
    }

    /** Appelée par la saisie d'un bilan (PR 3) : remet le compteur d'inactivité à zéro. */
    public function recordReport(CustomsOfficer $officer): void
    {
        $officer->forceFill(['last_report_at' => Carbon::now(), 'inactivity_notice_sent_at' => null])->save();
    }

    public function inactivityDays(Province $province): int
    {
        return ProvinceSecuritySettings::query()->where('province_id', $province->id)->value('customs_inactivity_days')
            ?? CustomsOfficer::DEFAULT_INACTIVITY_DAYS;
    }

    /** Réglage de la durée d'inactivité : prévôt ou connétable, ou le niveau 2 faute des deux. */
    public function setInactivityDays(Character $actor, Province $province, int $days): ProvinceSecuritySettings
    {
        if ($this->authority->customsDeciderLevel($actor, $province) === null) {
            throw MandateRefusal::make('inactivity_days', 'security.api.not_authorized');
        }

        if ($days < CustomsOfficer::MIN_INACTIVITY_DAYS || $days > CustomsOfficer::MAX_INACTIVITY_DAYS) {
            throw MandateRefusal::make('inactivity_days', 'security.api.inactivity_range', [
                'min' => CustomsOfficer::MIN_INACTIVITY_DAYS, 'max' => CustomsOfficer::MAX_INACTIVITY_DAYS,
            ]);
        }

        return ProvinceSecuritySettings::updateOrCreate(
            ['province_id' => $province->id],
            ['customs_inactivity_days' => $days, 'updated_by_character_id' => $actor->id],
        );
    }

    /** Moment où l'accès s'éteint faute de bilan, ou null si le compteur n'a pas de point de départ. */
    public function lapsesAt(CustomsOfficer $officer): ?Carbon
    {
        return $officer->inactivityReference()?->copy()->addDays($this->inactivityDays($officer->province));
    }

    public function isInactive(CustomsOfficer $officer, ?Carbon $now = null): bool
    {
        $lapse = $this->lapsesAt($officer);

        return $lapse !== null && ($now ?? Carbon::now())->greaterThanOrEqualTo($lapse);
    }

    /** Révocation automatique pour inactivité (tâche planifiée, province SANS trio connu). */
    public function revokeForInactivity(CustomsOfficer $officer): CustomsOfficer
    {
        return $this->close($officer, CustomsOfficer::REASON_INACTIVITY, null);
    }

    /**
     * Forme servie à l'API. Le pseudo n'est donné qu'à qui doit décider (`$forDecider`) et au douanier lui-même : il a demandé le rôle,
     * donc son pseudo n'est pas un tiers.
     *
     * @return array<string, mixed>
     */
    public function present(CustomsOfficer $officer, bool $forDecider = false): array
    {
        $officer->loadMissing('character', 'province', 'city');
        $lapse = $officer->status === CustomsOfficer::APPROVED ? $this->lapsesAt($officer) : null;

        return [
            'id' => $officer->id,
            'status' => $officer->status,
            'character_id' => $officer->character_id,
            'pseudo' => $forDecider ? $officer->character?->pseudo : null,
            'province' => ['id' => $officer->province_id, 'name' => $officer->province?->province_name],
            'city' => ['id' => $officer->city_id, 'name' => $officer->city?->city_name],
            'requested_at' => $officer->created_at?->toIso8601String(),
            'in_office_from' => $officer->in_office_from?->toIso8601String(),
            'last_report_at' => $officer->last_report_at?->toIso8601String(),
            'lapses_at' => $lapse?->toIso8601String(),
            'inactive' => $lapse !== null && Carbon::now()->greaterThanOrEqualTo($lapse),
        ];
    }

    private function assertPending(CustomsOfficer $officer): void
    {
        if ($officer->status !== CustomsOfficer::PENDING) {
            throw MandateRefusal::make('officer', 'security.api.not_pending');
        }
    }

    private function settle(CustomsOfficer $officer, bool $approve, ?Character $decider, int $level): CustomsOfficer
    {
        $now = Carbon::now();
        $officer->forceFill([
            'status' => $approve ? CustomsOfficer::APPROVED : CustomsOfficer::REJECTED,
            'active' => $approve ? 1 : null,
            'decided_by_character_id' => $decider?->id,
            'decision_level' => $level,
            'decided_at' => $now,
            'in_office_from' => $approve ? $now : null,
        ])->save();

        return $officer;
    }

    private function close(CustomsOfficer $officer, string $reason, ?Character $by): CustomsOfficer
    {
        $officer->forceFill([
            'status' => CustomsOfficer::REVOKED,
            'active' => null,
            'revoked_at' => Carbon::now(),
            'revoked_by_character_id' => $by?->id,
            'revocation_reason' => $reason,
        ])->save();

        return $officer;
    }
}
