<?php

namespace App\Services;

use App\Exceptions\InventoryAccessDenied;
use App\Exceptions\MandateRefusal;
use App\Models\Character;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Le DERNIER inventaire collé, enregistré CHIFFRÉ (EncryptedModuleData) au niveau PERSONNEL et au niveau VILLE : un seul
 * exemplaire par propriétaire, REMPLACÉ à chaque collage, sans historique (Cowork et Greg, 11/11/2026, fil
 * inventaire-bilans). La province a un historique : voir ProvinceInventoryRegistry. Le texte est conservé tel quel — le
 * parseur vit dans le navigateur. Mêmes autorisations que les réglages (InventorySettings::owner()).
 */
class InventoryText
{
    /** Longueur maximale du texte collé (un inventaire du jeu fait quelques Ko). */
    public const MAX_LENGTH = 200000;

    public function __construct(private readonly InventorySettings $settings) {}

    /** @return array{raw: ?string, saved_at: ?string, scope: array{level: string, id: int, name: string}} */
    public function get(Character $character, string $level): array
    {
        $owner = $this->owner($character, $level, false);
        $row = $owner['model']::query()->where($owner['column'], $owner['id'])->first();

        return $this->present($owner, $level, $row?->inventory, $row?->inventory_saved_at);
    }

    /**
     * @return array{raw: ?string, saved_at: ?string, scope: array{level: string, id: int, name: string}}
     *
     * @throws InventoryAccessDenied
     * @throws MandateRefusal
     */
    public function put(Character $character, string $level, string $raw): array
    {
        $owner = $this->owner($character, $level, true);
        self::assertText($raw);
        $now = Carbon::now();

        $row = DB::transaction(function () use ($character, $level, $owner, $raw, $now) {
            $row = $owner['model']::query()->where($owner['column'], $owner['id'])->lockForUpdate()->first();
            $attributes = ['inventory' => $raw, 'inventory_saved_at' => $now];
            if ($level === 'city') {
                $attributes['updated_by_character_id'] = $character->id;
            }

            // Pas encore de réglages : la ligne naît avec un document vide ([]), version 1.
            return $row
                ? tap($row)->update($attributes)
                : $owner['model']::create([$owner['column'] => $owner['id'], 'data' => [], 'version' => 1] + $attributes);
        });

        return $this->present($owner, $level, $row->inventory, $row->inventory_saved_at);
    }

    /** @throws MandateRefusal */
    public static function assertText(string $raw): void
    {
        if (trim($raw) === '') {
            throw MandateRefusal::make('raw', 'inventory.api.inventory_empty');
        }
        if (mb_strlen($raw) > self::MAX_LENGTH) {
            throw MandateRefusal::make('raw', 'inventory.api.inventory_too_large');
        }
    }

    /**
     * Seuls la ville et le personnage ont un exemplaire unique : la province passe par son historique.
     *
     * @return array{model: class-string, column: string, id: int, name: string}
     */
    private function owner(Character $character, string $level, bool $write): array
    {
        if (! in_array($level, ['personal', 'city'], true)) {
            throw new InventoryAccessDenied('inventory.api.level_unknown');
        }

        return $this->settings->owner($character, $level, $write);
    }

    /** @return array{raw: ?string, saved_at: ?string, scope: array{level: string, id: int, name: string}} */
    private function present(array $owner, string $level, ?string $raw, ?Carbon $savedAt): array
    {
        return [
            'raw' => $raw,
            'saved_at' => $savedAt?->toIso8601String(),
            'scope' => ['level' => $level, 'id' => $owner['id'], 'name' => $owner['name']],
        ];
    }
}
