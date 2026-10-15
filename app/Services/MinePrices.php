<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\Province;
use App\Models\ProvinceMinePrice;
use App\Support\MandateLabels;
use Illuminate\Support\Carbon;

/**
 * Prix des mines par période (brief admin/content/brief-prix-registre.md). Lecture ET écriture : le
 * contrôleur ne valide que la forme. Un jeu de prix entre en vigueur à `effective_from` et reste en
 * vigueur jusqu'au suivant ; le bilan d'un jour utilise le jeu en vigueur CE jour-là.
 *
 * Autorisation : `MandateAuthority::canManagePrices()` pour écrire maintenant, et
 * `canEditPriceOn()` pour la date visée (poste tenu à cette date, grâce de 7 jours). Pas de
 * suppression : un prix faux se corrige.
 */
class MinePrices
{
    public function __construct(private readonly MandateAuthority $authority) {}

    /** Jeu en vigueur à une date (le plus récent `effective_from` ≤ date), ou null. */
    public function getPriceForDate(int $provinceId, string $date): ?ProvinceMinePrice
    {
        return ProvinceMinePrice::query()
            ->where('province_id', $provinceId)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** Jeu en vigueur aujourd'hui (jour de Paris). */
    public function getCurrentPrice(int $provinceId): ?ProvinceMinePrice
    {
        return $this->getPriceForDate($provinceId, Carbon::now(config('mandates.timezone'))->toDateString());
    }

    /**
     * Prix à figer dans le `payload` d'un relevé (forme historique : `prices` par ressource + `rate`),
     * ou null si aucun jeu n'est en vigueur à cette date.
     *
     * @return array{prices: array<string, float>, rate: float}|null
     */
    public function payloadFor(int $provinceId, string $date): ?array
    {
        $price = $this->getPriceForDate($provinceId, $date);

        return $price ? ['prices' => $price->prices(), 'rate' => $price->hourly_rate] : null;
    }

    /**
     * @param  array{effective_from: string, prices: array<string, numeric>, hourly_rate: numeric}  $data
     *
     * @throws MandateRefusal
     */
    public function create(Character $actor, Province $province, array $data): ProvinceMinePrice
    {
        $this->assertCanWrite($actor, $province, $data['effective_from']);
        $this->assertFree($province, $data['effective_from']);

        return ProvinceMinePrice::create($this->attributes($data) + [
            'province_id' => $province->id,
            'created_by_character_id' => $actor->id,
        ])->load('province', 'createdBy', 'updatedBy');
    }

    /**
     * @param  array{effective_from?: string, prices: array<string, numeric>, hourly_rate: numeric}  $data
     *
     * @throws MandateRefusal
     */
    public function update(Character $actor, ProvinceMinePrice $price, array $data): ProvinceMinePrice
    {
        $province = $price->province;
        if (! $this->authority->canEditPrice($actor, $price)) {
            throw MandateRefusal::make('price', 'mines.api.price_locked', ['days' => config('mandates.price_edit_grace_days')]);
        }

        $date = $data['effective_from'] ?? $price->effective_from->toDateString();
        if ($date !== $price->effective_from->toDateString()) {
            $this->assertCanWrite($actor, $province, $date);
            $this->assertFree($province, $date, $price->id);
        }

        $price->update($this->attributes($data + ['effective_from' => $date]) + ['updated_by_character_id' => $actor->id]);

        return $price->load('province', 'createdBy', 'updatedBy');
    }

    /** Jeux de prix créés ou modifiés depuis un instant, du plus ancien au plus récent (résumé quotidien). */
    public function changedSince(Province $province, Carbon $since)
    {
        return ProvinceMinePrice::query()
            ->where('province_id', $province->id)
            ->where('updated_at', '>', $since)
            ->with('createdBy', 'updatedBy')
            ->orderBy('updated_at')
            ->get();
    }

    /** Forme JSON d'un jeu de prix ; `editable` dit si LE LECTEUR peut le corriger. */
    public function present(ProvinceMinePrice $price, bool $editable): array
    {
        $author = fn (?Character $c) => $c?->pseudo;

        return [
            'id' => $price->id,
            'province_id' => $price->province_id,
            'effective_from' => $price->effective_from->toDateString(),
            'prices' => $price->prices(),
            'hourly_rate' => $price->hourly_rate,
            'created_by' => $author($price->createdBy),
            'updated_by' => $author($price->updatedBy),
            'created_at' => $price->created_at?->toIso8601String(),
            'updated_at' => $price->updated_at?->toIso8601String(),
            'editable' => $editable,
        ];
    }

    /** Libellé FR/EN d'un poste, pour les notifications. */
    public static function officeLabel(string $key, string $locale): string
    {
        return MandateLabels::office($key, $locale);
    }

    private function assertCanWrite(Character $actor, Province $province, string $date): void
    {
        $managed = $this->authority->canManagePrices($actor);
        if (! $managed || $managed->id !== $province->id) {
            throw MandateRefusal::make('character', 'mines.api.price_not_authorized');
        }
        // Pas de prix daté du futur : sa date d'effet ne tomberait dans aucune période de poste connue,
        // et il serait impossible à corriger. Pas de prix antidaté hors de SA période non plus : sans
        // cela, le verrou de 7 jours se contournerait en écrivant dans le passé d'un autre.
        if ($date > Carbon::now(config('mandates.timezone'))->toDateString()) {
            throw MandateRefusal::make('effective_from', 'mines.api.price_future');
        }
        if (! $this->authority->canEditPriceOn($actor, $province, $date)) {
            throw MandateRefusal::make('effective_from', 'mines.api.price_outside_office');
        }
    }

    private function assertFree(Province $province, string $date, ?int $exceptId = null): void
    {
        $exists = ProvinceMinePrice::query()
            ->where('province_id', $province->id)
            ->whereDate('effective_from', $date)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw MandateRefusal::make('effective_from', 'mines.api.price_exists', ['date' => Carbon::parse($date)->format('d/m/Y')]);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $attributes = ['effective_from' => $data['effective_from'], 'hourly_rate' => $data['hourly_rate']];
        foreach (ProvinceMinePrice::COLUMNS as $resource => $column) {
            $attributes[$column] = $data['prices'][$resource];
        }

        return $attributes;
    }
}
