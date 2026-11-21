<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dates des mandats. Toutes les durées sont des JOURS DU JEU, dans le fuseau du jeu (config
 * mandates.timezone) : le jeu change de jour à 04:00 heure de Paris, pas à minuit (constat de Greg,
 * 20/11/2026 ; fil mandats-heure-bascule, validé par Cowork). On part donc de 04:00 de ce fuseau, on
 * ajoute des jours (addDays, jamais addMonth), puis on stocke en UTC. Un changement d'heure au milieu
 * du mandat ne décale donc la fin ni d'une heure ni d'un jour : elle reste à 04:00 heure de Paris.
 * Un mandat « commencé le 21/10 » entre en fonction le 21/10 à 04:00 et, pour 30 jours, finit le 20/11
 * à 04:00.
 *
 * 🔴 L'heure de bascule est une CONSTANTE, jamais une clé de `config/*.php` : un fichier de config
 * modifié ou ajouté n'est pas pris par le cache de configuration de la prod (incident du 04/10/2026).
 */
class MandateCalendar
{
    /** Heure (locale du jeu) à laquelle le jeu change de jour. */
    public const GAME_DAY_STARTS_AT_HOUR = 4;

    public static function timezone(): string
    {
        return config('mandates.timezone');
    }

    /** Début du jour du jeu donné (04:00, heure du jeu) — en UTC. */
    public static function startOfGameDay(CarbonInterface|string $date): Carbon
    {
        $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;

        return Carbon::createFromFormat('Y-m-d', $day, self::timezone())
            ->startOfDay()->setTime(self::GAME_DAY_STARTS_AT_HOUR, 0)->utc();
    }

    /** Fin du jour du jeu donné = début du suivant (04:00 le lendemain) — en UTC. */
    public static function endOfGameDay(CarbonInterface|string $date): Carbon
    {
        return self::addGameDays(self::startOfGameDay($date), 1);
    }

    /** Le jour du jeu EN COURS, au format Y-m-d : avant 04:00, c'est encore la veille du calendrier. */
    public static function today(): string
    {
        return self::gameDayOf(Carbon::now());
    }

    /** Le jour du jeu (Y-m-d) auquel appartient un instant : 03:59 appartient encore à la veille, 04:00 au jour suivant. */
    public static function gameDayOf(CarbonInterface $instant): string
    {
        return Carbon::instance($instant)->setTimezone(self::timezone())->subHours(self::GAME_DAY_STARTS_AT_HOUR)->format('Y-m-d');
    }

    /** Décale un jour du jeu (Y-m-d) de N jours civils — en dates pures, sans heure : aucun changement d'heure ne peut le déplacer. */
    public static function shiftGameDay(string $day, int $days): string
    {
        return Carbon::createFromFormat('!Y-m-d', $day, 'UTC')->addDays($days)->format('Y-m-d');
    }

    /** Ajoute des jours civils du fuseau du jeu à un instant stocké en UTC. */
    public static function addGameDays(CarbonInterface $instant, int $days): Carbon
    {
        return Carbon::instance($instant)->setTimezone(self::timezone())->addDays($days)->utc();
    }

    public static function durationDays(string $level): int
    {
        return $level === 'mayor' ? config('mandates.mayor_days') : config('mandates.council_days');
    }

    /**
     * Fin NOMINALE : début + 30 ou 60 jours. Calculée à l'approbation ; recalculée seulement par
     * une correction de `started_at` par l'admin, jamais à la volée.
     */
    public static function validUntil(string $level, CarbonInterface|string $startedAt): Carbon
    {
        return self::addGameDays(self::startOfGameDay($startedAt), self::durationDays($level));
    }

    /**
     * Fin EFFECTIVE à l'approbation : la fin nominale pour un maire ; pour un conseiller, plus le
     * délai d'élection du dirigeant (le sortant reste en poste jusque-là). C'est aussi la valeur de
     * réparation si un `holds_until` a été écrasé par erreur : elle ne dépend que de valid_until.
     */
    public static function nominalHoldsUntil(string $level, CarbonInterface $validUntil): Carbon
    {
        return $level === 'mayor'
            ? Carbon::instance($validUntil)
            : self::addGameDays($validUntil, config('mandates.council_grace_days'));
    }
}
