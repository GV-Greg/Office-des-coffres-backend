<?php

namespace App\Support;

use App\Models\CouncilMandate;
use App\Models\Mandate;
use Illuminate\Support\Carbon;

/**
 * Libellés des mandats dans une langue donnée (fr pour tout, en pour ce qui part chez le joueur).
 *
 * ⚠️ __() renvoie la CLÉ BRUTE quand une traduction manque. Pour un motif, on retombe sur le CODE
 * (« date_incoherente »), jamais sur « mandates.reasons.date_incoherente » ni sur un vide : un refus
 * affiché sans motif serait pire que pas de motif. Les codes vivent en texte dans la base, sans FK,
 * donc une vieille ligne peut porter un code dont le libellé a disparu.
 */
class MandateLabels
{
    public static function reason(?string $code, string $locale): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        $key = "mandates.reasons.{$code}";
        $label = __($key, [], $locale);

        return $label === $key ? $code : $label;
    }

    public static function office(?string $key, string $locale): string
    {
        if ($key === null) {
            return __('mandates.no_office', [], $locale);
        }

        $translationKey = "mandates.offices.{$key}";
        $label = __($translationKey, [], $locale);

        return $label === $translationKey ? $key : $label;
    }

    /**
     * « Maire de Ville » ou « Conseil comtal de Province — Titre ». Noms de lieux du jeu, non
     * traduits. Pour un conseiller, le titre est celui de la période EN VIGUEUR (Q17).
     */
    public static function post(Mandate $mandate, string $locale): string
    {
        if ($mandate instanceof CouncilMandate) {
            return __('mandates.post.council', [
                'place' => $mandate->province?->province_name,
                'office' => self::office($mandate->currentOfficeKey(), $locale),
            ], $locale);
        }

        return __('mandates.post.mayor', ['place' => $mandate->city?->city_name], $locale);
    }

    /**
     * La même date en ANNÉE DU JEU (22/11/2026 → 22/11/1474), pour les textes rédigés par le
     * serveur : `:date (:date_jeu)` dans les emails (fil mandats-historique, 10). Jamais en base.
     */
    public static function gameDate(?\DateTimeInterface $instant): string
    {
        if ($instant === null) {
            return '';
        }
        $local = Carbon::instance($instant)->setTimezone(MandateCalendar::timezone());

        return $local->format('d/m/').GameCalendar::gameYear((int) $local->format('Y'));
    }

    /** Date affichée au joueur : jour civil du fuseau du jeu. */
    public static function date(?\DateTimeInterface $instant): string
    {
        return $instant === null ? '' : Carbon::instance($instant)
            ->setTimezone(MandateCalendar::timezone())->format('d/m/Y');
    }
}
