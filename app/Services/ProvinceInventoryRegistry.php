<?php

namespace App\Services;

use App\Exceptions\InventoryAccessDenied;
use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\ProvinceInventorySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEUL écrivain de l'historique des inventaires d'une PROVINCE (Cowork et Greg, 11/11/2026, fil inventaire-bilans),
 * calqué sur MineRegistry : la mémoire de la province, transmise d'un commissaire au commerce au suivant.
 *
 * ÉCRITURE : le commissaire au commerce en fonction seulement, province du POSTE. Date = jour de Paris où l'on colle
 * (le texte du jeu ne porte aucune date). Un second collage le même jour REMPLACE le premier : l'ancien passe `active`
 * NULL avec `replaced_by_id` et reste en base, rien ne s'efface. Un texte identique à celui du jour ne crée rien.
 * LECTURE : le commissaire au commerce et le dirigeant ; un NOUVEAU commissaire voit l'historique COMPLET de ses
 * prédécesseurs (des faits provinciaux, pas des biens personnels). L'auteur n'est jamais nommé.
 * Les bilans se calculent dans le navigateur : le serveur sert les textes déchiffrés.
 */
class ProvinceInventoryRegistry
{
    public const OFFICE_KEY = 'commissaire_commerce';

    /** Exemplaires servis par lecture (les plus récents). */
    public const HISTORY_LIMIT = 100;

    public function __construct(private readonly MandateAuthority $authority) {}

    /**
     * @return array{snapshot: ProvinceInventorySnapshot, replaced: bool, unchanged: bool}
     *
     * @throws InventoryAccessDenied
     * @throws MandateRefusal
     */
    public function record(Character $character, string $raw): array
    {
        $province = $this->authority->canManageProvinceInventory($character)
            ?? throw new InventoryAccessDenied('inventory.api.province_not_authorized');
        InventoryText::assertText($raw);
        $today = Carbon::now(config('mandates.timezone'))->toDateString();

        return DB::transaction(function () use ($character, $raw, $province, $today) {
            $existing = ProvinceInventorySnapshot::query()
                ->where('province_id', $province->id)
                ->whereDate('taken_on', $today)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->payload === $raw) {
                return ['snapshot' => $existing, 'replaced' => false, 'unchanged' => true];
            }
            // L'ancien d'abord : l'index unique (province, jour, active) n'accepte pas deux actifs.
            $existing?->update(['active' => null]);

            $snapshot = ProvinceInventorySnapshot::create([
                'province_id' => $province->id,
                'taken_on' => $today,
                'character_id' => $character->id,
                'office_key' => self::OFFICE_KEY,
                'payload' => $raw,
            ]);
            $existing?->update(['replaced_by_id' => $snapshot->id, 'replaced_at' => Carbon::now()]);

            return ['snapshot' => $snapshot, 'replaced' => $existing !== null, 'unchanged' => false];
        });
    }

    /**
     * L'historique de la province : les exemplaires en vigueur, du plus récent au plus ancien, déchiffrés.
     *
     * @return array{province: array{id: int, name: string}, snapshots: list<array<string, mixed>>, can_write: bool}
     *
     * @throws InventoryAccessDenied
     */
    public function history(Character $character): array
    {
        $province = $this->authority->canReadProvinceInventory($character)
            ?? throw new InventoryAccessDenied('inventory.api.province_not_reader');

        $snapshots = ProvinceInventorySnapshot::query()
            ->where('province_id', $province->id)
            ->where('active', true)
            ->orderByDesc('taken_on')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (ProvinceInventorySnapshot $s) => [
                'id' => $s->id,
                'taken_on' => $s->taken_on->toDateString(),
                'saved_at' => $s->created_at?->toIso8601String(),
                'office_key' => $s->office_key,
                // Jamais le pseudo : seulement si c'est soi — l'auteur d'un exemplaire est un fait de gestion, pas une identité.
                'by_me' => $s->character_id === $character->id,
                'raw' => $s->payload,
            ])
            ->values()
            ->all();

        return [
            'province' => ['id' => $province->id, 'name' => $province->province_name],
            'snapshots' => $snapshots,
            'can_write' => $this->authority->canManageProvinceInventory($character)?->id === $province->id,
        ];
    }
}
