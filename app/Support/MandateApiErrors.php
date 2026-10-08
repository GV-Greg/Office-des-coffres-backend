<?php

namespace App\Support;

use App\Exceptions\MandateRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Forme de TOUTE réponse d'erreur de l'API des mandats (fil admin/echanges/mandats-lot2, Q1, Q8,
 * Q9, Q10). Un seul endroit porte les trois garanties :
 *
 *  1. COMPLÈTE : chaque champ en erreur porte un `code` et ses `messages` {fr, en} — qu'il vienne
 *     d'un refus métier (MandateRefusal) ou de la validation de forme de Laravel (Q10) ; un 404 ou
 *     un 429 porte `code` et `messages` au premier niveau.
 *  2. BILINGUE : aucune langue n'est stockée sur le compte, le serveur ne peut pas choisir — il
 *     envoie les deux. `messages.fr` est TOUJOURS la chaîne de `errors` (même clé) : un test le
 *     vérifie, la duplication ne peut pas dériver.
 *  3. AUCUN code `admin.` ne sort par l'API : un refus d'administration routé vers un joueur est
 *     une erreur de programmation, qui échoue bruyamment ici au lieu d'envoyer du français.
 *
 * ⚠️ Le frontend n'a AUCUNE table de correspondance code → texte : il affiche `messages[sa langue]`
 * et n'utilise le code que pour sa logique d'écran. Ne pas en ajouter une.
 * ⚠️ Pas de `status_label` ici ni ailleurs dans l'API : un statut (actif, en prolongation…) est une
 * présentation choisie par le frontend, pas une donnée du backend (Q11). Le backend ne porte que
 * les libellés dont il a déjà besoin pour un email (titres, motifs, causes de fin).
 */
class MandateApiErrors
{
    public const LOCALES = ['fr', 'en'];

    public static function covers(Request $request): bool
    {
        return $request->is('api/v1/mandates', 'api/v1/mandates/*', 'api/v1/characters/*/mandates', 'api/v1/characters/*/province', 'api/v1/characters/*/mine-reports', 'api/v1/council-offices');
    }

    public static function validation(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        [$codes, $messages] = $exception instanceof MandateRefusal
            ? self::fromRefusal($exception)
            : self::fromValidator($exception);

        self::assertPlayerFacing($codes);

        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'errors' => $errors,
            'codes' => $codes,
            'messages' => $messages,
        ], $exception->status);
    }

    /** Erreur sans champ (404 d'un mandat ou d'un personnage, 429) : code et messages au premier niveau. */
    public static function error(int $status, string $key, array $params = [], array $headers = []): JsonResponse
    {
        $code = MandateRefusal::codeFor($key);
        self::assertPlayerFacing([$code]);

        return response()->json([
            'success' => false,
            'message' => __($key, $params, 'fr'),
            'code' => $code,
            'messages' => self::bothLocales($key, $params),
        ], $status, $headers);
    }

    /** @return array{0: array<string, string>, 1: array<string, array<string, string>>} */
    private static function fromRefusal(MandateRefusal $refusal): array
    {
        $messages = [];
        foreach ($refusal->translations as $field => [$key, $params]) {
            $messages[$field] = self::bothLocales($key, $params);
        }

        return [$refusal->codes, $messages];
    }

    /**
     * Validation de forme (Q10) : code `validation.<règle>` de la première règle en échec, messages
     * régénérés dans chaque langue avec les mêmes données et les mêmes règles.
     *
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private static function fromValidator(ValidationException $exception): array
    {
        $validator = $exception->validator;
        $codes = [];
        foreach ($validator->failed() as $field => $rules) {
            $codes[$field] = 'validation.'.Str::snake((string) array_key_first($rules));
        }

        $translator = app('translator');
        $current = $translator->getLocale();
        $byLocale = [];
        try {
            foreach (self::LOCALES as $locale) {
                $translator->setLocale($locale);
                $byLocale[$locale] = Validator::make($validator->getData(), $validator->getRules())->errors();
            }
        } finally {
            $translator->setLocale($current);
        }

        $messages = [];
        foreach (array_keys($codes) as $field) {
            foreach (self::LOCALES as $locale) {
                $messages[$field][$locale] = $byLocale[$locale]->first($field);
            }
        }

        return [$codes, $messages];
    }

    /** @return array<string, string> */
    private static function bothLocales(string $key, array $params): array
    {
        return collect(self::LOCALES)->mapWithKeys(fn (string $locale) => [$locale => __($key, $params, $locale)])->all();
    }

    /** @param  array<int|string, string>  $codes */
    private static function assertPlayerFacing(array $codes): void
    {
        foreach ($codes as $code) {
            if (str_starts_with($code, 'admin.')) {
                throw new LogicException("Code d'administration « {$code} » émis par l'API des mandats : ce refus ne doit pas atteindre un joueur.");
            }
        }
    }
}
