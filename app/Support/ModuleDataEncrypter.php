<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chiffreur des données de module.
 *
 * Distinct de l'`encrypter` du framework, et c'est tout l'intérêt : celui-ci est alimenté par
 * `APP_KEY`, qu'un `php artisan key:generate` fait tourner en routine. Perdre `APP_KEY` coûte une
 * reconnexion ; perdre la clé des modules rendrait leurs données définitivement illisibles.
 *
 * Ce que le chiffrement achète ici est de la friction, pas une impossibilité d'accès : qui détient
 * l'hébergement, la base et le code peut toujours lire ce que l'application affiche. L'objectif
 * est qu'un `SELECT *` en phpMyAdmin ne rende rien d'exploitable, et que lire suppose d'écrire
 * délibérément un script qui amorce Laravel.
 *
 * Voir admin/strategies/donnees-utilisateur.md §5.
 */
class ModuleDataEncrypter extends Encrypter
{
    /**
     * Construit le chiffreur à partir de la config `module_data`.
     *
     * @param  array{key?: string|null, previous_keys?: array<int, string>, cipher?: string}  $config
     */
    public static function fromConfig(array $config): self
    {
        $key = $config['key'] ?? null;

        if ($key === null || $key === '') {
            throw new RuntimeException(
                "MODULE_DATA_KEY est absente de l'environnement. Les données de module ne sont "
                .'jamais chiffrées avec APP_KEY : une rotation de celle-ci les rendrait illisibles. '
                .'Générer une clé avec : php -r "echo \'base64:\'.base64_encode(random_bytes(32)).PHP_EOL;" '
                .'— voir admin/strategies/donnees-utilisateur.md §5.'
            );
        }

        $encrypter = new self(
            static::parseKey($key),
            $cipher = $config['cipher'] ?? 'AES-256-CBC'
        );

        // Essayées au déchiffrement uniquement (Encrypter::getAllKeys), jamais au chiffrement :
        // une donnée réécrite repart toujours sur la clé courante.
        return $encrypter->previousKeys(array_map(
            static fn (string $previous): string => static::parseKey($previous),
            array_values(array_filter($config['previous_keys'] ?? []))
        ));
    }

    /**
     * Même traitement du préfixe `base64:` que `EncryptionServiceProvider::parseKey()`, pour que
     * `MODULE_DATA_KEY` s'écrive exactement comme `APP_KEY` dans le `.env`.
     */
    protected static function parseKey(string $key): string
    {
        return Str::startsWith($key, $prefix = 'base64:')
            ? base64_decode(Str::after($key, $prefix))
            : $key;
    }
}
