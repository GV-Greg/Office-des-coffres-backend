<?php

namespace Database\Seeders;

use App\Models\AnimActivityType;
use Illuminate\Database\Seeder;

/**
 * Types d'activité du module Animation. Idempotent (firstOrCreate sur `key`) : se relance sans
 * risque. ⚠️ Il ne touche PAS `is_active` d'un type existant — l'administration peut l'avoir
 * désactivé, un rejeu ne doit pas le réactiver.
 *
 * 🔴 Un NOUVEAU type naît INACTIF (décision de Greg, 11/11/2026) : sans cela, le déploiement le ferait
 * apparaître dans le menu et accepter des créations avant que le texte légal soit en ligne. Il s'active
 * ensuite depuis /anim (bouton « Activer »), une fois le texte publié. Décode le code, déjà en ligne, reste actif.
 */
class AnimActivityTypeSeeder extends Seeder
{
    /** clé du type => actif à sa création. */
    public const TYPES = ['decode' => true, 'rewards' => false];

    public function run(): void
    {
        foreach (self::TYPES as $key => $active) {
            AnimActivityType::firstOrCreate(['key' => $key], ['is_active' => $active]);
        }
    }
}
