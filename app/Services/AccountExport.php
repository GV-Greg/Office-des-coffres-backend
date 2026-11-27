<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterInventorySettings;
use App\Models\CouncilMandate;
use App\Models\CustomsReport;
use App\Models\CustomsWatchlistEntry;
use App\Models\CustomsOfficer;
use App\Models\MayorMandate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Construit le payload JSON de l'export art. 20 RGPD.
 *
 * Chaque section est une méthode privée : pour qu'un module ajoute la sienne,
 * il suffit d'ajouter une méthode ici — le contrôleur n'a pas à bouger.
 *
 */
class AccountExport
{
    public function build(User $user): array
    {
        $user->load([
            'roles',
            'characters.city.province.kingdom',
            'characters.mayorMandates.city.province.kingdom',
            'characters.councilMandates.province.kingdom',
            'characters.councilMandates.officePeriods.office',
        ]);

        return [
            'format'       => 'office-des-coffres/account-export',
            'version'      => 1,
            'generated_at' => Carbon::now()->toIso8601ZuluString(),
            'service'      => [
                'name'       => 'Office des Coffres',
                'url'        => 'https://officedescoffres.creacube.be',
                'unofficial' => true,
            ],
            'account'      => $this->accountSection($user),
            'characters'   => $this->charactersSection($user),
            'mandates'     => $this->mandatesSection($user),
            'inventory'    => $this->inventorySection($user),
            'customs_activity' => $this->customsActivitySection($user),
            'customs'      => $this->customsSection($user),
            'not_included' => $this->notIncludedSection(),
        ];
    }

    private function accountSection(User $user): array
    {
        return [
            'email'             => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
            'created_at'        => $user->created_at->toIso8601ZuluString(),
            'updated_at'        => $user->updated_at->toIso8601ZuluString(),
            'roles'             => $user->getRoleNames()->values()->all(),
        ];
    }

    private function charactersSection(User $user): array
    {
        return $user->characters->map(fn (Character $c) => [
            'pseudo'                   => $c->pseudo,
            'residence'                => [
                'city'     => $c->city?->city_name,
                'province' => $c->city?->province?->province_name,
                'kingdom'  => $c->city?->province?->kingdom?->kingdom_name,
            ],
            'is_validated'             => $c->is_validated,
            'pending_residence_change' => $c->pending_residence_change,
            'created_at'               => $c->created_at->toIso8601ZuluString(),
            'updated_at'               => $c->updated_at->toIso8601ZuluString(),
        ])->all();
    }

    private function mandatesSection(User $user): array
    {
        $mandates = [];

        foreach ($user->characters as $character) {
            foreach ($character->mayorMandates as $mandate) {
                $mandates[] = [
                    'level'            => 'mayor',
                    'place'            => [
                        'city'     => $mandate->city?->city_name,
                        'province' => $mandate->city?->province?->province_name,
                        'kingdom'  => $mandate->city?->province?->kingdom?->kingdom_name,
                    ],
                    'title'            => null,
                    'started_at'       => $mandate->started_at?->toDateString(),
                    'valid_until'      => $mandate->valid_until?->toIso8601ZuluString(),
                    'in_office_from'   => $mandate->in_office_from?->toIso8601ZuluString(),
                    'holds_until'      => $mandate->holds_until?->toIso8601ZuluString(),
                    'status'           => $mandate->effectiveStatus(),
                    'announcement_url' => $mandate->announcement_url,
                    'decision_message' => $mandate->decision_message,
                ];
            }

            foreach ($character->councilMandates as $mandate) {
                // Poste détenu maintenant, attente du successeur comprise.
                $currentPeriod = $mandate->currentPeriod();

                $mandates[] = [
                    'level'            => 'council',
                    'place'            => [
                        'province' => $mandate->province?->province_name,
                        'kingdom'  => $mandate->province?->kingdom?->kingdom_name,
                    ],
                    'title'            => $currentPeriod?->office?->key,
                    'started_at'       => $mandate->started_at?->toDateString(),
                    'valid_until'      => $mandate->valid_until?->toIso8601ZuluString(),
                    'in_office_from'   => $mandate->in_office_from?->toIso8601ZuluString(),
                    'holds_until'      => $mandate->holds_until?->toIso8601ZuluString(),
                    'status'           => $mandate->effectiveStatus(),
                    'announcement_url' => $mandate->announcement_url,
                    'decision_message' => $mandate->decision_message,
                ];
            }
        }

        return $mandates;
    }

    /**
     * Réglages d'inventaire de chacun de ses personnages (catégories, assignations, options d'export, visibilité) et le
     * dernier inventaire collé, déchiffré — des données que le joueur a fournies (art. 20). Les réglages de ville et de
     * province, et l'historique des inventaires de la province, n'y sont PAS : donnée communautaire (catégorie C).
     *
     * @return list<array<string, mixed>>
     */
    private function inventorySection(User $user): array
    {
        $pseudos = $user->characters->pluck('pseudo', 'id');

        return CharacterInventorySettings::query()
            ->whereIn('character_id', $pseudos->keys())
            ->orderBy('character_id')
            ->get()
            ->map(fn (CharacterInventorySettings $row) => [
                'character'               => $pseudos[$row->character_id],
                'settings'                => $row->data ?: null,
                'last_inventory'          => $row->inventory,
                'last_inventory_saved_at' => $row->inventory_saved_at?->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Statut de douanier de chacun de ses personnages (module Sécurité, brief §10) : demande, accès, dates, fin éventuelle. Le décideur
     * n'y est PAS nommé (un autre joueur) ; seul le niveau de la décision l'est.
     *
     * @return list<array<string, mixed>>
     */
    private function customsSection(User $user): array
    {
        $pseudos = $user->characters->pluck('pseudo', 'id');

        return CustomsOfficer::query()
            ->whereIn('character_id', $pseudos->keys())
            ->with('province.kingdom', 'city')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomsOfficer $row) => [
                'character'       => $pseudos[$row->character_id],
                'province'        => $row->province?->province_name,
                'city'            => $row->city?->city_name,
                'status'          => $row->status,
                'requested_at'    => $row->created_at?->toIso8601ZuluString(),
                'decision_level'  => $row->decision_level,
                'decided_at'      => $row->decided_at?->toIso8601ZuluString(),
                'in_office_from'  => $row->in_office_from?->toIso8601ZuluString(),
                'last_report_at'  => $row->last_report_at?->toIso8601ZuluString(),
                'revoked_at'      => $row->revoked_at?->toIso8601ZuluString(),
                'revocation_reason' => $row->revocation_reason,
            ])
            ->values()
            ->all();
    }

    /**
     * Trace d'activité du Registre de douane, SANS CONTENU (décision de Greg, 22/11/2026 : « beaucoup trop de données » pour les bilans, pas de
     * contenu de liste non plus) : « une entrée a été ajoutée à une liste » (jour, couleur) et « un bilan a été saisi » (jour, ville, sections
     * présentes). Jamais un pseudo, une raison ni un texte de bilan. Tirée des lignes ENCORE PRÉSENTES : une entrée retirée ne laisse aucune trace.
     *
     * @return list<array<string, mixed>>
     */
    private function customsActivitySection(User $user): array
    {
        $ids = $user->characters->pluck('id');
        $pseudos = $user->characters->pluck('pseudo', 'id');

        $entries = CustomsWatchlistEntry::query()->whereIn('added_by_character_id', $ids)->get()->map(fn (CustomsWatchlistEntry $entry) => [
            'type'      => 'list_entry_added',
            'character' => $pseudos[$entry->added_by_character_id],
            'at'        => $entry->created_at->toIso8601ZuluString(),
            'colour'    => $entry->colour,
        ]);

        $reports = CustomsReport::query()->whereIn('character_id', $ids)->where('active', true)->with('city')->get()->map(fn (CustomsReport $report) => [
            'type'      => 'report_entered',
            'character' => $pseudos[$report->character_id],
            'at'        => $report->created_at->toIso8601ZuluString(),
            'day'       => $report->reported_on->toDateString(),
            'city'      => $report->city?->city_name,
            'sections'  => array_keys($report->payload['sections'] ?? []),
        ]);

        return $entries->concat($reports)->sortBy('at')->values()->all();
    }

    private function notIncludedSection(): array
    {
        return [
            'browser_storage' =>
                'Vos préférences (thème, langue, consentement) et vos saisies dans les modules ' .
                "publics (Bilan des mines, Guet) sont conservées dans votre navigateur uniquement — " .
                "elles ne sont pas transmises à nos serveurs et n'apparaissent donc pas ici. / " .
                "Your preferences (theme, language, consent) and your entries in the public modules " .
                "(Mine Balance, Watch) are stored in your browser only — they are not sent to our " .
                "servers and therefore do not appear here.",
            'shared_data' =>
                "Les réglages et l'inventaire de la ville ou de la province que vous gérez (maire, " .
                "commissaire au commerce), ainsi que les naissances et les prix de la nourriture du Livre des naissances, " .
                "appartiennent à la collectivité, pas à vous : ils n'apparaissent pas ici. / " .
                "The settings and inventory of the city or province you manage (mayor, Trade Minister), and the births " .
                "and food prices of the Birth Register, belong to the community, not to you: they do not appear here.",
            'credentials' =>
                'Votre mot de passe (stocké sous forme hachée, non réversible) et vos jetons de ' .
                "connexion sont exclus de cet export pour votre sécurité. / " .
                "Your password (stored as a hash, non-reversible) and your login tokens are " .
                "excluded from this export for your security.",
        ];
    }
}
