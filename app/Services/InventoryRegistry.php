<?php

namespace App\Services;

use App\Exceptions\InventoryAccessDenied;
use App\Exceptions\InventoryReplacementNeedsConfirmation;
use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registre des stocks — socle des deux registres de TERRITOIRE (fil admin/echanges/registre-stocks, 20/11/2026) : la province
 * (commissaire au commerce) et la ville (maire). SEUL écrivain de son niveau ; une sous-classe dit le modèle, la clé du lieu, le
 * poste qui écrit et celui qui lit. Le niveau personnel n'a PAS de registre (Q4 : reporté).
 *
 * ÉCRITURE : le titulaire en fonction seulement, lieu du POSTE. Date = jour de Paris où l'on colle (le texte du jeu ne porte aucune
 * date). Un second collage le même jour REMPLACE le premier — mais seulement avec `confirm_replace` (409 sinon, Q6) : l'ancien
 * passe `active` NULL avec `replaced_by_id` et reste en base, rien ne s'efface. Un texte identique à celui du jour ne crée rien.
 * LECTURE : un NOUVEAU titulaire voit l'historique COMPLET de ses prédécesseurs (des faits du territoire, pas des biens personnels).
 * AUTEURS (Q5) : jamais nommés dans la liste, sauf le rédacteur REMPLACÉ (`replaced`) et celui de l'exemplaire à remplacer (409) —
 * c'est ce que la politique §3 promet. Les bilans se calculent dans le navigateur : le serveur sert les textes déchiffrés.
 */
abstract class InventoryRegistry
{
    /** Exemplaires servis par lecture (les plus récents). */
    public const HISTORY_LIMIT = 100;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Colonne du lieu : `province_id` ou `city_id`. */
    abstract protected function placeColumn(): string;

    /** Estampille du poste à l'écriture. */
    abstract protected function officeKey(): string;

    /** Le lieu que le personnage peut ÉCRIRE maintenant, ou null. */
    abstract protected function writablePlace(Character $character): ?Model;

    /** Le lieu que le personnage peut LIRE maintenant, ou null. */
    abstract protected function readablePlace(Character $character): ?Model;

    abstract protected function placeName(Model $place): string;

    /** Clé `inventory.api.*` du refus d'écriture. */
    abstract protected function deniedWriteKey(): string;

    /** Clé `inventory.api.*` du refus de lecture. */
    abstract protected function deniedReadKey(): string;

    /**
     * @return array{snapshot: Model, replaced: ?Model, unchanged: bool}
     *
     * @throws InventoryAccessDenied
     * @throws InventoryReplacementNeedsConfirmation
     * @throws MandateRefusal
     */
    public function record(Character $character, string $raw, bool $confirmReplace = false): array
    {
        $place = $this->writablePlace($character) ?? throw new InventoryAccessDenied($this->deniedWriteKey());
        InventoryText::assertText($raw);
        $today = MandateCalendar::today();
        $model = $this->model();

        return DB::transaction(function () use ($character, $raw, $place, $today, $confirmReplace, $model) {
            $existing = $model::query()
                ->where($this->placeColumn(), $place->id)
                ->whereDate('taken_on', $today)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->payload === $raw) {
                return ['snapshot' => $existing, 'replaced' => null, 'unchanged' => true];
            }
            if ($existing && ! $confirmReplace) {
                throw new InventoryReplacementNeedsConfirmation($existing->loadMissing('character'), $this->placeName($place));
            }
            // L'ancien d'abord : l'index unique (lieu, jour, active) n'accepte pas deux actifs.
            $existing?->update(['active' => null]);

            $snapshot = $model::create([
                $this->placeColumn() => $place->id,
                'taken_on' => $today,
                'character_id' => $character->id,
                'office_key' => $this->officeKey(),
                'payload' => $raw,
            ]);
            $existing?->update(['replaced_by_id' => $snapshot->id, 'replaced_at' => Carbon::now()]);

            return ['snapshot' => $snapshot, 'replaced' => $existing?->loadMissing('character'), 'unchanged' => false];
        });
    }

    /**
     * L'historique du lieu : les exemplaires en vigueur, du plus récent au plus ancien, déchiffrés.
     *
     * @return array{place: array{id: int, name: string}, snapshots: list<array<string, mixed>>, can_write: bool}
     *
     * @throws InventoryAccessDenied
     */
    public function history(Character $character): array
    {
        $place = $this->readablePlace($character) ?? throw new InventoryAccessDenied($this->deniedReadKey());
        $model = $this->model();

        $snapshots = $model::query()
            ->where($this->placeColumn(), $place->id)
            ->where('active', true)
            ->with('replaces.character')
            ->orderByDesc('taken_on')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (Model $s) => [
                'id' => $s->id,
                'taken_on' => $s->taken_on->toDateString(),
                'saved_at' => $s->created_at?->toIso8601String(),
                'office_key' => $s->office_key,
                // L'auteur de CET exemplaire n'est jamais nommé : seulement si c'est soi. Le pseudo n'apparaît que pour le rédacteur
                // d'un exemplaire REMPLACÉ (`replaced`), comme la politique §3 le promet.
                'by_me' => $s->character_id === $character->id,
                'replaced' => $s->replaces ? self::author($s->replaces) + ['replaced_at' => $s->replaces->replaced_at?->toIso8601String()] : null,
                'raw' => $s->payload,
            ])
            ->values()
            ->all();

        return [
            'place' => ['id' => $place->id, 'name' => $this->placeName($place)],
            'snapshots' => $snapshots,
            'can_write' => $this->writablePlace($character)?->id === $place->id,
        ];
    }

    /**
     * Qui a écrit un exemplaire — nommé à l'écran quand il est remplacé (Q5). `pseudo` NULL une fois le personnage supprimé.
     *
     * @return array{id: int, pseudo: ?string, office_key: string, office_label: array{fr: string, en: string}, taken_on: string, saved_at: ?string}
     */
    public static function author(Model $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'pseudo' => $snapshot->character?->pseudo,
            'office_key' => $snapshot->office_key,
            'office_label' => ['fr' => MandateLabels::office($snapshot->office_key, 'fr'), 'en' => MandateLabels::office($snapshot->office_key, 'en')],
            'taken_on' => $snapshot->taken_on->toDateString(),
            'saved_at' => $snapshot->created_at?->toIso8601String(),
        ];
    }
}
