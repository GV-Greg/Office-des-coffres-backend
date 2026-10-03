<?php

namespace Database\Seeders;

use App\Models\CouncilOffice;
use Illuminate\Database\Seeder;

/**
 * Les 10 postes titrés d'un conseil comtal, relevés en jeu par Greg le 02/10/2026 (FR et EN côte
 * à côte, voir lang/{fr,en}/mandates.php). Clés nommées depuis le FRANÇAIS : `connetable` a pour
 * libellé anglais « Sergeant » et `prevot_des_marechaux` « Constable » — une clé `constable` serait
 * une mine à la relecture.
 *
 * Idempotent (updateOrCreate sur `key`) : à la différence de MapSeeder, il se relance sans risque.
 */
class CouncilOfficeSeeder extends Seeder
{
    public const KEYS = [
        'leader',
        'commissaire_commerce',
        'commissaire_mines',
        'juge',
        'prevot_des_marechaux',
        'procureur',
        'connetable',
        'capitaine',
        'porte_parole',
        'bailli',
    ];

    public function run(): void
    {
        foreach (self::KEYS as $position => $key) {
            CouncilOffice::updateOrCreate(['key' => $key], ['position' => $position + 1]);
        }
    }
}
