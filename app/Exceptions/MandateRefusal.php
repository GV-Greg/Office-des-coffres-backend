<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Refus métier des mandats (MandateWorkflow). Reste une ValidationException — l'administration
 * Blade la reçoit comme une erreur de validation ordinaire (retour au formulaire, message
 * français) —, mais porte en plus, par champ, son CODE et sa clé de traduction : la couche API en
 * tire les messages FR et EN (fil mandats-lot2, Q1, Q8, Q9).
 */
class MandateRefusal extends ValidationException
{
    /** @var array<string, string> champ => code */
    public array $codes = [];

    /** @var array<string, array{0: string, 1: array<string, mixed>}> champ => [clé, paramètres] */
    public array $translations = [];

    /** @param  array<string, mixed>  $params */
    public static function make(string $field, string $key, array $params = []): self
    {
        $refusal = static::withMessages([$field => __($key, $params, 'fr')]);
        $refusal->codes[$field] = self::codeFor($key);
        $refusal->translations[$field] = [$key, $params];

        return $refusal;
    }

    /**
     * `mandates.api.dead_born` → `dead_born` ; toute autre clé (administration) → `admin.<dernier
     * segment>`. Le préfixe n'a aucune valeur mécanique, voir MandateWorkflow::refuse().
     */
    public static function codeFor(string $key): string
    {
        // Registre des mines : même contrat, ses clés sous mines.api.* (codes préfixés mine_).
        if (str_starts_with($key, 'mines.api.')) {
            return substr($key, strlen('mines.api.'));
        }

        return str_starts_with($key, 'mandates.api.')
            ? substr($key, strlen('mandates.api.'))
            : 'admin.'.substr($key, strrpos($key, '.') + 1);
    }
}
