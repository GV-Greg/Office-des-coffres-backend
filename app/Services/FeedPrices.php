<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\Province;
use App\Models\ProvinceFeedPrice;
use App\Support\Livestock;
use App\Support\MandateCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SEUL écrivain des prix de la nourriture du cheptel. Mêmes règles que les prix des mines : un jeu entre en vigueur à sa date
 * et le reste jusqu'au suivant ; le bailli l'écrit pour un jour de son mandat, jamais futur, et plus après le terme. Pas de
 * suppression : un prix faux se corrige.
 */
class FeedPrices
{
    public function __construct(private readonly MandateAuthority $authority) {}

    /**
     * @param  array{effective_from: string, prices: array<string, numeric|null>}  $data
     *
     * @throws MandateRefusal
     */
    public function create(Character $actor, Province $province, array $data): ProvinceFeedPrice
    {
        $this->assertDate($actor, $province, $data['effective_from']);
        $this->assertFree($province, $data['effective_from']);

        return ProvinceFeedPrice::create($this->attributes($data) + [
            'province_id' => $province->id,
            'created_by_character_id' => $actor->id,
        ]);
    }

    /**
     * @param  array{effective_from: string, prices: array<string, numeric|null>}  $data
     *
     * @throws MandateRefusal
     */
    public function update(Character $actor, Province $province, ProvinceFeedPrice $price, array $data): ProvinceFeedPrice
    {
        if (! $this->canEdit($actor, $province, $price)) {
            throw MandateRefusal::make('price', 'livestock.api.price_locked');
        }

        $date = $data['effective_from'];
        if ($date !== $price->effective_from->toDateString()) {
            $this->assertDate($actor, $province, $date);
            $this->assertFree($province, $date, $price->id);
        }

        $price->update($this->attributes($data) + ['updated_by_character_id' => $actor->id]);

        return $price;
    }

    public function canEdit(Character $actor, Province $province, ProvinceFeedPrice $price): bool
    {
        return $this->authority->canEditFeedPriceOn($actor, $province, $price->effective_from->toDateString());
    }

    /** Tous les jeux de la province (jusqu'au jour `$to`), le plus récent d'abord. Aucun auteur n'est nommé. */
    public function all(Province $province, ?Character $writer = null, ?string $to = null): Collection
    {
        return ProvinceFeedPrice::query()
            ->where('province_id', $province->id)
            ->when($to, fn ($query) => $query->whereDate('effective_from', '<=', $to))
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (ProvinceFeedPrice $price) => $this->present($price, $writer !== null && $this->canEdit($writer, $province, $price)))
            ->values();
    }

    /** @return array<string, mixed> */
    public function present(ProvinceFeedPrice $price, bool $editable): array
    {
        return [
            'id' => $price->id,
            'effective_from' => $price->effective_from->toDateString(),
            'prices' => $price->prices(),
            'editable' => $editable,
        ];
    }

    private function assertDate(Character $actor, Province $province, string $date): void
    {
        if ($date > MandateCalendar::today()) {
            throw MandateRefusal::make('effective_from', 'livestock.api.price_future');
        }
        if (! $this->authority->canEditFeedPriceOn($actor, $province, $date)) {
            throw MandateRefusal::make('effective_from', 'livestock.api.price_outside_office');
        }
    }

    private function assertFree(Province $province, string $date, ?int $exceptId = null): void
    {
        $exists = ProvinceFeedPrice::query()
            ->where('province_id', $province->id)
            ->whereDate('effective_from', $date)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw MandateRefusal::make('effective_from', 'livestock.api.price_exists', ['date' => Carbon::parse($date)->format('d/m/Y')]);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $attributes = ['effective_from' => $data['effective_from']];
        foreach (Livestock::FEEDS as $feed) {
            $attributes['price_'.$feed] = $data['prices'][$feed] ?? null;
        }

        return $attributes;
    }
}
