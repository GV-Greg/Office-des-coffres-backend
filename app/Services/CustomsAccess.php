<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\CustomsOfficer;
use App\Models\Province;
use App\Support\MandateCalendar;
use Illuminate\Support\Collection;

/**
 * Matrice d'accès du Registre de douane (brief admin/content/brief-module-securite.md §3, corrigée par Greg les 21 et 22/11/2026). SEUL endroit
 * qui dit qui lit et qui écrit quoi ; les services (CustomsReports, CustomsWatchlist) et les contrôleurs le consultent, le frontend ne le recode pas.
 *
 * | Rôle (maintenant, dans la province) | Bilans                      | Listes                                                  |
 * | Prévôt, connétable                  | lecture + saisie + correction | lecture + écriture                                   |
 * | Capitaine                           | lecture + saisie             | lecture + écriture                                      |
 * | Maire                               | sa ville (toute la province s'il est aussi douanier) | lecture ; écriture SEULEMENT sans trio connu |
 * | Douanier validé                     | lecture + saisie, toute la province | lecture ; écriture SEULEMENT sans trio connu    |
 * | Juge                                | rien                         | lecture + AJOUT au rouge (jamais de retrait)            |
 * | Procureur                           | rien                         | lecture seule                                           |
 * | Dirigeant (comte, duc…)             | lecture seule                | lecture seule                                           |
 *
 * ⚠️ « Sans trio connu » est l'état COURANT d'une province (limite 2 de MandateAuthority : l'Office ne connaît que les mandats déclarés) : le
 * repli est le mode normal, dit à l'écran (`has_security_trio`). Il ne s'étend JAMAIS à un conseiller sans titre.
 * ⚠️ Le brief refuse au procureur les bilans ; la politique (§6) les lui donnait. Le code suit le brief — il donne MOINS que la politique n'autorise.
 */
class CustomsAccess
{
    /** Jours du jeu en arrière que le prévôt et le connétable peuvent saisir ou corriger (Greg, 22/11/2026, P6). */
    public const BACKDATE_DAYS = 7;

    private const PRIMARY = ['prevot_des_marechaux', 'connetable'];

    private const TRIO = MandateAuthority::SECURITY_TRIO;

    public function __construct(private readonly MandateAuthority $authority) {}

    /**
     * @return array{
     *   province: Province, office: ?string, role: string, trio_known: bool,
     *   read_reports: bool, write_reports: bool, correct_reports: bool,
     *   read_lists: bool, write_lists: bool, add_red_only: bool, sees_authors: bool,
     *   customs: ?CustomsOfficer
     * }|null
     */
    public function for(Character $character): ?array
    {
        $scope = $this->authority->securityScope($character);
        if (! $scope) {
            return null;
        }

        $office = $scope['office'];
        $mayor = $scope['mayor_city'] !== null;
        $customs = $scope['customs'] !== null;
        $primary = in_array($office, self::PRIMARY, true);
        $trio = in_array($office, self::TRIO, true);
        $trioKnown = $this->authority->provinceHasSecurityTrio($scope['province']);

        $access = [
            'province' => $scope['province'],
            'office' => $office,
            'role' => $this->role($office, $mayor, $customs),
            'trio_known' => $trioKnown,
            'read_reports' => $trio || $mayor || $customs || $office === 'leader',
            'write_reports' => $trio || $mayor || $customs,
            'correct_reports' => $primary,
            'read_lists' => $trio || $mayor || $customs || in_array($office, ['juge', 'procureur', 'leader'], true),
            'write_lists' => $trio || (($mayor || $customs) && ! $trioKnown),
            'add_red_only' => $office === 'juge',
            'sees_authors' => $trio,
            'customs' => $scope['customs'],
            'mayor_city' => $scope['mayor_city'],
        ];

        return $access['read_reports'] || $access['read_lists'] || $access['add_red_only'] ? $access : null;
    }

    /**
     * Villes de la province où ce rôle SAISIT un bilan : le trio et les douaniers, toutes ; le maire seul, la sienne.
     *
     * @return Collection<int, City>
     */
    public function writableCities(array $access): Collection
    {
        if (! $access['write_reports']) {
            return collect();
        }

        $all = in_array($access['office'], self::TRIO, true) || $access['customs'] !== null;

        return $all ? $this->citiesOf($access['province']) : collect([$access['mayor_city']])->filter();
    }

    /** Villes dont ce rôle LIT les bilans : comme pour la saisie, plus toute la province pour le dirigeant. */
    public function readableCities(array $access): Collection
    {
        if (! $access['read_reports']) {
            return collect();
        }

        return $access['office'] === 'leader' ? $this->citiesOf($access['province']) : $this->writableCities($access);
    }

    public function canWriteIn(array $access, City $city): bool
    {
        return $this->writableCities($access)->contains('id', $city->id);
    }

    public function canReadIn(array $access, City $city): bool
    {
        return $this->readableCities($access)->contains('id', $city->id);
    }

    /** Jours du jeu où ce rôle peut saisir : aujourd'hui seulement, sauf prévôt et connétable (jusqu'à 7 jours en arrière). */
    public function earliestDay(array $access): string
    {
        return $access['correct_reports'] ? MandateCalendar::shiftGameDay(MandateCalendar::today(), -self::BACKDATE_DAYS) : MandateCalendar::today();
    }

    private function citiesOf(Province $province): Collection
    {
        return City::query()->where('province_id', $province->id)->orderBy('city_name')->get();
    }

    private function role(?string $office, bool $mayor, bool $customs): string
    {
        if (in_array($office, self::TRIO, true)) {
            return $office;
        }

        return $customs ? 'customs' : ($mayor ? 'mayor' : (string) $office);
    }
}
