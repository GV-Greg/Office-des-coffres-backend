<?php

namespace Database\Factories;

use App\Models\Kingdom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Province>
 */
class ProvinceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kingdom_id' => Kingdom::factory(),
            'province_name' => fake()->unique()->city() . ' Province',
        ];
    }
}
