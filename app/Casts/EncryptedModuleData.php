<?php

namespace App\Casts;

use App\Support\ModuleDataEncrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast des colonnes « payload » des modules : chiffrées avec `MODULE_DATA_KEY`, jamais `APP_KEY`.
 *
 * ⚠️ **Ne jamais poser ce cast sur un axe** (`city_id`, `province_id`, `character_id`,
 * `reported_at`). Une colonne chiffrée n'est ni indexable, ni triable, ni filtrable en SQL : le
 * cipher utilise un IV aléatoire, donc la même valeur produit un chiffré différent à chaque
 * écriture et même l'égalité ne fonctionne pas. On chiffre le contenu, jamais les axes — voir
 * admin/strategies/donnees-utilisateur.md §5.
 *
 * Usage :
 *   protected function casts(): array
 *   {
 *       return ['payload' => EncryptedModuleData::class.':array'];
 *   }
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class EncryptedModuleData implements CastsAttributes
{
    /**
     * @param  string  $type  'string' (défaut) ou 'array' pour un payload structuré.
     */
    public function __construct(protected string $type = 'string') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        // `serialize: false` : on gère nous-mêmes le JSON pour que la valeur stockée reste lisible
        // par n'importe quel langage une fois déchiffrée, et non par PHP seul.
        $plain = $this->encrypter()->decrypt($value, false);

        return $this->type === 'array'
            ? json_decode($plain, true, 512, JSON_THROW_ON_ERROR)
            : $plain;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return [$key => null];
        }

        $plain = $this->type === 'array'
            ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : (string) $value;

        return [$key => $this->encrypter()->encrypt($plain, false)];
    }

    protected function encrypter(): ModuleDataEncrypter
    {
        return app(ModuleDataEncrypter::class);
    }
}
