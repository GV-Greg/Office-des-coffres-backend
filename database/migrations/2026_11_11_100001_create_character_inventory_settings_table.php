<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglages d'inventaire d'un PERSONNAGE (catégories personnalisées et masquées, ordre, couleur des titres,
     * assignations item → catégorie, options d'export). Greg, 11/11/2026 : « l'utilité du compte, c'est de se
     * connecter partout et de retrouver ses paramètres » ; « c'est l'inventaire du personnage ».
     *
     * Catégorie A (donnees-utilisateur.md §2) : produit au titre de SOI-MÊME (§5ter), il disparaît avec le
     * personnage ou le compte — la cascade est garantie par la base. UN document JSON par personnage (le modèle
     * qu'avait déjà le navigateur), validé par App\Support\InventorySettingsDocument, jamais un texte libre.
     * `version` : contrôle d'écriture (409 si un autre appareil a écrit entre-temps).
     * Non chiffré : des étiquettes et des noms d'objets, pas de donnée financière (MODULE_DATA_KEY est réservée
     * aux relevés des mines). Le texte de l'inventaire COLLÉ est conservé ici, chiffré (colonne `inventory`).
     */
    public function up(): void
    {
        Schema::create('character_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            // Dernier inventaire collé, CHIFFRÉ (EncryptedModuleData) : un seul exemplaire, REMPLACÉ à chaque collage —
            // pas d'historique à ce niveau (Cowork et Greg, 11/11/2026, fil inventaire-bilans). Axes en clair, payload chiffré.
            $table->text('inventory')->nullable();
            $table->timestamp('inventory_saved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_inventory_settings');
    }
};
