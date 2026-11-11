<?php

namespace App\Services;

use App\Exceptions\InventoryAccessDenied;
use App\Exceptions\InventoryCopyNeedsConfirmation;
use App\Exceptions\InventoryVersionConflict;
use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CharacterInventorySettings;
use App\Models\CityInventorySettings;
use App\Models\ProvinceInventorySettings;
use App\Support\InventorySettingsDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SEUL écrivain des réglages d'inventaire, aux trois niveaux (fil admin/echanges/inventaire-scopes ; Greg, 11/11/2026) :
 *
 *  - `personal` : le PERSONNAGE lui-même (catégorie A, supprimé avec lui) ;
 *  - `city` : la ville du MANDAT du maire en fonction (catégorie C) ;
 *  - `province` : la province du POSTE du commissaire au commerce en fonction ; le dirigeant lit seulement (catégorie C).
 *
 * Le niveau et le propriétaire se résolvent ICI, depuis le poste tenu MAINTENANT (MandateAuthority) — jamais depuis le
 * corps de la requête. Écriture à version : un client qui a lu la version N n'écrit que sur N (sinon 409 avec l'état
 * courant). Un document vaut au plus MAX_BYTES, de clés connues (InventorySettingsDocument).
 */
class InventorySettings
{
    public function __construct(private readonly MandateAuthority $authority) {}

    /**
     * Ce que le personnage peut faire MAINTENANT, et pour qui : l'écran l'affiche avant tout envoi, la règle reste ici.
     *
     * @return array{personal: array<string, mixed>, city: ?array<string, mixed>, province: ?array<string, mixed>}
     */
    public function scopes(Character $character): array
    {
        $city = $this->authority->inventoryCity($character);
        $read = $this->authority->canReadProvinceInventory($character);
        $write = $this->authority->canManageProvinceInventory($character);

        return [
            'personal' => ['id' => $character->id, 'name' => $character->pseudo, 'can_write' => true, 'can_read' => true],
            'city' => $city ? ['id' => $city->id, 'name' => $city->city_name, 'can_write' => true, 'can_read' => true] : null,
            'province' => $read ? ['id' => $read->id, 'name' => $read->province_name, 'can_write' => $write?->id === $read->id, 'can_read' => true] : null,
        ];
    }

    /**
     * Le propriétaire d'un niveau pour ce personnage.
     *
     * @return array{model: class-string<Model>, column: string, id: int, name: string}
     *
     * @throws InventoryAccessDenied
     */
    public function owner(Character $character, string $level, bool $write): array
    {
        switch ($level) {
            case 'personal':
                return ['model' => CharacterInventorySettings::class, 'column' => 'character_id', 'id' => $character->id, 'name' => $character->pseudo];
            case 'city':
                $city = $this->authority->inventoryCity($character)
                    ?? throw new InventoryAccessDenied('inventory.api.city_not_authorized');

                return ['model' => CityInventorySettings::class, 'column' => 'city_id', 'id' => $city->id, 'name' => $city->city_name];
            case 'province':
                $province = $write ? $this->authority->canManageProvinceInventory($character) : $this->authority->canReadProvinceInventory($character);
                $province ?? throw new InventoryAccessDenied($write ? 'inventory.api.province_not_authorized' : 'inventory.api.province_not_reader');

                return ['model' => ProvinceInventorySettings::class, 'column' => 'province_id', 'id' => $province->id, 'name' => $province->province_name];
        }

        throw new InventoryAccessDenied('inventory.api.level_unknown');
    }

    /**
     * @return array{data: ?array<string, mixed>, version: int, updated_at: ?string, scope: array{level: string, id: int, name: string}}
     *
     * @throws InventoryAccessDenied
     */
    public function show(Character $character, string $level): array
    {
        $owner = $this->owner($character, $level, false);

        return $this->present($owner, $level, $this->row($owner));
    }

    /**
     * Écrit le document. `$expectedVersion` = la version que le client a lue (0 = rien lu : première écriture).
     *
     * @param  array<string, mixed>  $data
     * @return array{data: ?array<string, mixed>, version: int, updated_at: ?string, scope: array{level: string, id: int, name: string}}
     *
     * @throws InventoryAccessDenied
     * @throws InventoryVersionConflict
     * @throws MandateRefusal
     */
    public function save(Character $character, string $level, array $data, int $expectedVersion): array
    {
        $owner = $this->owner($character, $level, true);
        $document = InventorySettingsDocument::validate($data, $level);

        return DB::transaction(function () use ($character, $level, $owner, $document, $expectedVersion) {
            $row = $this->row($owner, lock: true);
            $current = $row?->version ?? 0;
            if ($current !== $expectedVersion) {
                throw new InventoryVersionConflict($this->present($owner, $level, $row));
            }

            $attributes = ['data' => $document, 'version' => $current + 1];
            if ($level !== 'personal') {
                $attributes['updated_by_character_id'] = $character->id;
            }
            $row = $row
                ? tap($row)->update($attributes)
                : $owner['model']::create([$owner['column'] => $owner['id']] + $attributes);

            return $this->present($owner, $level, $row->refresh());
        });
    }

    /**
     * Copie les réglages PERSONNELS d'un personnage vers un autre du MÊME compte (Greg, 11/11/2026 : « pouvoir
     * éventuellement copier vers un autre de ses personnages »). Remplace tout ; attend `$confirmReplace` si la cible
     * a déjà des réglages. Copie le document seulement, JAMAIS le texte collé : chaque personnage a son inventaire.
     * L'appelant a déjà vérifié que les deux personnages sont ceux du compte.
     *
     * @return array{data: ?array<string, mixed>, version: int, updated_at: ?string, scope: array{level: string, id: int, name: string}}
     *
     * @throws InventoryCopyNeedsConfirmation
     * @throws MandateRefusal
     */
    public function copyPersonal(Character $target, Character $source, bool $confirmReplace): array
    {
        if ($target->is($source)) {
            throw MandateRefusal::make('source', 'inventory.api.copy_same_character');
        }
        $from = CharacterInventorySettings::query()->where('character_id', $source->id)->first();
        if (! $from || $from->data === [] || $from->data === null) {
            throw MandateRefusal::make('source', 'inventory.api.copy_source_empty', ['source' => $source->pseudo]);
        }

        return DB::transaction(function () use ($target, $source, $from, $confirmReplace) {
            $existing = CharacterInventorySettings::query()->where('character_id', $target->id)->lockForUpdate()->first();
            if ($existing && $existing->data !== [] && $existing->data !== null && ! $confirmReplace) {
                throw new InventoryCopyNeedsConfirmation($target->pseudo, $source->pseudo);
            }

            $attributes = ['data' => $from->data, 'version' => ($existing?->version ?? 0) + 1];
            $row = $existing
                ? tap($existing)->update($attributes)
                : CharacterInventorySettings::create(['character_id' => $target->id] + $attributes);
            $owner = ['model' => CharacterInventorySettings::class, 'column' => 'character_id', 'id' => $target->id, 'name' => $target->pseudo];

            return $this->present($owner, 'personal', $row->refresh());
        });
    }

    /** @param  array{model: class-string<Model>, column: string, id: int, name: string}  $owner */
    private function row(array $owner, bool $lock = false): ?Model
    {
        $query = $owner['model']::query()->where($owner['column'], $owner['id']);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @param  array{model: class-string<Model>, column: string, id: int, name: string}  $owner
     * @return array{data: ?array<string, mixed>, version: int, updated_at: ?string, scope: array{level: string, id: int, name: string}}
     */
    private function present(array $owner, string $level, ?Model $row): array
    {
        return [
            'data' => $row?->data,
            'version' => $row?->version ?? 0,
            'updated_at' => $row?->updated_at?->toIso8601String(),
            'scope' => ['level' => $level, 'id' => $owner['id'], 'name' => $owner['name']],
        ];
    }
}
