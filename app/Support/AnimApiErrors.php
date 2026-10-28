<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Contrat d'erreur de l'API du module Animation : MÊME forme que celle des mandats (codes et messages
 * {fr, en} sur toute erreur, jamais de table code → texte côté frontend), branché séparément dans
 * bootstrap/app.php avec ses propres routes. La mécanique vit dans MandateApiErrors, non dupliquée :
 * seul le périmètre (`covers`) est propre au module.
 */
class AnimApiErrors
{
    public static function covers(Request $request): bool
    {
        return $request->is(
            'api/v1/anim/*',
            'api/v1/characters/*/decode-activities',
            'api/v1/decode-activities/*',
        );
    }

    public static function validation(ValidationException $exception): JsonResponse
    {
        return MandateApiErrors::validation($exception);
    }

    public static function error(int $status, string $key, array $params = [], array $headers = []): JsonResponse
    {
        return MandateApiErrors::error($status, $key, $params, $headers);
    }
}
