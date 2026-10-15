<?php

namespace Database\Factories;

use App\Models\Province;
use App\Models\ProvinceMinePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProvinceMinePrice>
 */
class ProvinceMinePriceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'province_id' => Province::factory(),
            'effective_from' => '2026-10-01',
            'price_or' => 1.5,
            'price_fer' => 2.25,
            'price_pierre' => 20,
            'price_argile' => 3,
            'price_sel' => 4,
            'hourly_rate' => 0.7,
        ];
    }
}
