<?php

namespace App\Support;

use RuntimeException;

/**
 * Empreinte de l'adresse IP de celui qui crée une activité SANS COMPTE : on peut compter les activités ouvertes
 * d'une même connexion et la reconnaître, on ne relit JAMAIS l'adresse (HMAC-SHA256, aucune adresse en base ni en journal).
 *
 * Clé : celle des données de module (`MODULE_DATA_KEY`), déjà présente et sauvegardée ailleurs que la base — aucune
 * variable de plus à reporter à la main sur le serveur. Elle sert ici de secret de HMAC, jamais de chiffrement.
 *
 * `$prefix` isole le domaine de hachage par type d'activité : chaque type passe son propre préfixe, les empreintes
 * restent indépendantes d'un type à l'autre (une grille ouverte ne bloque pas un décodage, et vice-versa).
 *
 * ⚠️ Une adresse privée, de bouclage ou réservée n'est PAS fingerprintée (nul) : derrière un répartiteur mal configuré
 * toutes les requêtes semblent venir de la même adresse, et « une seule activité par connexion » bloquerait tout le monde.
 */
class AnimIp
{
    public static function fingerprint(?string $ip, string $prefix): ?string
    {
        if ($ip === null || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $key = (string) config('module_data.key');
        if ($key === '') {
            // Jamais d'empreinte sans secret : mieux vaut refuser que d'enregistrer une valeur devinable.
            throw new RuntimeException('MODULE_DATA_KEY absente : impossible de calculer l\'empreinte de connexion.');
        }

        return hash_hmac('sha256', $ip, $prefix.'|'.$key);
    }
}
