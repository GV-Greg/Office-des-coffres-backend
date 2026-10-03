<?php

use Database\Seeders\CouncilOfficeSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sème les 10 postes titrés en appelant le seeder IDEMPOTENT (arbitrage du 03/10/2026, fil
     * admin/echanges/mandats-lot1, Q12).
     *
     * ⚠️ Ce couplage migration → seeder est voulu, ne pas le « nettoyer » :
     *  - le déploiement ne lance ni migration ni seeder : un seeder à part serait un geste de plus
     *    à se rappeler en prod, et sans lui GET /council-offices répondrait une liste vide ;
     *  - des INSERT écrits ici feraient de chaque correction de libellé ou de poste une nouvelle
     *    migration — or la liste s'est déjà révélée fausse une fois (un poste manquant) ;
     *  - le seeder est idempotent (updateOrCreate sur `key`) : le rejouer à la main pour une
     *    correction reste sans risque.
     */
    public function up(): void
    {
        (new CouncilOfficeSeeder)->run();
    }

    public function down(): void
    {
        // Rien : la table elle-même disparaît avec la migration précédente.
    }
};
