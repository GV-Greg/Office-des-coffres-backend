<?php

namespace Database\Seeders;

use App\Models\AnimActivityType;
use Illuminate\Database\Seeder;

/**
 * Types d'activité du module Animation. Idempotent (firstOrCreate sur `key`) : se relance sans
 * risque. ⚠️ Il ne touche PAS `is_active` d'un type existant — l'administration peut l'avoir
 * désactivé, un rejeu ne doit pas le réactiver.
 */
class AnimActivityTypeSeeder extends Seeder
{
    public const KEYS = ['decode'];

    public function run(): void
    {
        foreach (self::KEYS as $key) {
            AnimActivityType::firstOrCreate(['key' => $key], ['is_active' => true]);
        }
    }
}
