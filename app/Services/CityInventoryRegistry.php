<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\CityInventorySnapshot;
use App\Models\Mandate;
use App\Models\MayorMandate;
use Illuminate\Database\Eloquent\Model;

/**
 * Historique des inventaires d'une VILLE (fil admin/echanges/registre-stocks, 20/11/2026) : la mémoire de la ville, transmise d'un maire
 * au suivant. Mécanique commune : voir InventoryRegistry. Distinct du dernier inventaire collé de `city_inventory_settings` (un seul
 * exemplaire, remplacé à chaque collage) : ici, un exemplaire par jour, inscrit par un geste volontaire.
 *
 * ÉCRITURE ET LECTURE : le maire en fonction MAINTENANT, ville de son mandat (jamais la résidence) — le maire seul, personne d'autre.
 * Estampille de poste : `MayorMandate::LEVEL` (une seule source de la clé, aucune config).
 */
class CityInventoryRegistry extends InventoryRegistry
{
    public const OFFICE_KEY = MayorMandate::LEVEL;

    /** Un mandat de maire dure 30 jours du jeu : mi-mandat à 15, fin à 30. */
    public const MID_DAYS = 15;

    public const END_DAYS = 30;

    public function __construct(private readonly MandateAuthority $authority) {}

    protected function model(): string
    {
        return CityInventorySnapshot::class;
    }

    protected function placeColumn(): string
    {
        return 'city_id';
    }

    protected function officeKey(): string
    {
        return self::OFFICE_KEY;
    }

    protected function writablePlace(Character $character): ?Model
    {
        return $this->authority->inventoryCity($character);
    }

    protected function readablePlace(Character $character): ?Model
    {
        return $this->authority->inventoryCity($character);
    }

    protected function midMandateDays(): int
    {
        return self::MID_DAYS;
    }

    protected function endMandateDays(): int
    {
        return self::END_DAYS;
    }

    protected function writableMandate(Character $character): ?Mandate
    {
        return $this->authority->activeMayorMandate($character);
    }

    protected function readerMandate(Character $character): ?Mandate
    {
        return $this->authority->activeMayorMandate($character);
    }

    protected function placeName(Model $place): string
    {
        /** @var City $place */
        return $place->city_name;
    }

    protected function deniedWriteKey(): string
    {
        return 'inventory.api.city_not_authorized';
    }

    protected function deniedReadKey(): string
    {
        return 'inventory.api.city_not_authorized';
    }
}
