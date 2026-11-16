<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forme plurielle d'un item (décision de Greg, 16/11/2026). Le jeu écrit « 1 Kilo de minerai de
     * fer » mais « 2 Kilos de minerai de fer » : une icône doit se retrouver sous les deux formes.
     * Une ligne = un item = un ID, avec son singulier (`item_name`) et, s'il diffère, son pluriel.
     * Nullable : un item invariable (« Fer ») n'en a pas besoin. Unique, comme `item_name` ; l'unicité
     * croisée des deux colonnes est tenue par `ItemIcon::nameRules()`, qu'aucun index ne sait exprimer.
     *
     * Table hors taxinomie données-utilisateur : aucun lien vers un compte ou un personnage.
     */
    public function up(): void
    {
        Schema::table('item_icons', function (Blueprint $table) {
            $table->string('item_name_plural', 150)->nullable()->unique()->after('item_name');
        });
    }

    public function down(): void
    {
        Schema::table('item_icons', function (Blueprint $table) {
            $table->dropUnique(['item_name_plural']);
            $table->dropColumn('item_name_plural');
        });
    }
};
