<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Mandate;
use App\Models\Province;
use App\Models\ProvinceInventorySnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Historique des inventaires d'une PROVINCE (Cowork et Greg, 11/11/2026, fil inventaire-bilans), calqué sur MineRegistry : la mémoire de
 * la province, transmise d'un commissaire au commerce au suivant. Mécanique commune : voir InventoryRegistry.
 *
 * ÉCRITURE : le commissaire au commerce en fonction seulement (le bailli et le commissaire aux mines n'ont aucun accès — fil
 * inventaire-scopes, confirmé Q2 du fil registre-stocks), province du POSTE. LECTURE : lui et le dirigeant.
 */
class ProvinceInventoryRegistry extends InventoryRegistry
{
    public const OFFICE_KEY = 'commissaire_commerce';

    public function __construct(private readonly MandateAuthority $authority) {}

    protected function model(): string
    {
        return ProvinceInventorySnapshot::class;
    }

    protected function placeColumn(): string
    {
        return 'province_id';
    }

    protected function officeKey(): string
    {
        return self::OFFICE_KEY;
    }

    protected function writablePlace(Character $character): ?Model
    {
        return $this->authority->canManageProvinceInventory($character);
    }

    protected function readablePlace(Character $character): ?Model
    {
        return $this->authority->canReadProvinceInventory($character);
    }

    protected function writableMandate(Character $character): ?Mandate
    {
        return $this->authority->provinceInventoryMandate($character);
    }

    protected function readerMandate(Character $character): ?Mandate
    {
        return $this->authority->provinceInventoryReaderMandate($character);
    }

    protected function placeName(Model $place): string
    {
        /** @var Province $place */
        return $place->province_name;
    }

    protected function deniedWriteKey(): string
    {
        return 'inventory.api.province_not_authorized';
    }

    protected function deniedReadKey(): string
    {
        return 'inventory.api.province_not_reader';
    }
}
