<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterInventorySettings;
use App\Models\CouncilMandate;
use App\Models\MayorMandate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Construit le payload JSON de l'export art. 20 RGPD.
 *
 * Chaque section est une méthode privée : pour qu'un module ajoute la sienne,
 * il suffit d'ajouter une méthode ici — le contrôleur n'a pas à bouger.
 *
 * Brief : admin/content/brief-export-donnees.md (Lot 1).
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
        $now      = Carbon::now();

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
                // Poste détenu maintenant : filtrage sur la collection déjà chargée.
                $currentPeriod = $mandate->officePeriods
                    ->first(fn ($p) => $p->started_at <= $now && $p->ended_at > $now);

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
                "commissaire au commerce) appartiennent à la collectivité, pas à vous : ils n'apparaissent pas ici. / " .
                "The settings and inventory of the city or province you manage (mayor, Trade Minister) " .
                "belong to the community, not to you: they do not appear here.",
            'credentials' =>
                'Votre mot de passe (stocké sous forme hachée, non réversible) et vos jetons de ' .
                "connexion sont exclus de cet export pour votre sécurité. / " .
                "Your password (stored as a hash, non-reversible) and your login tokens are " .
                "excluded from this export for your security.",
        ];
    }
}
