<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dates des mandats. Toutes les durées sont des JOURS CIVILS du fuseau du jeu (config
 * mandates.timezone) : on part de 00:00 de ce fuseau, on ajoute des jours (addDays, jamais
 * addMonth), puis on stocke en UTC. Un changement d'heure au milieu du mandat ne décale donc la
 * fin ni d'une heure ni d'un jour.
 */
class MandateCalendar
{
    public static function timezone(): string
    {
        return config('mandates.timezone');
    }

    /** 00:00, heure du jeu, du jour donné — en UTC. */
    public static function startOfGameDay(CarbonInterface|string $date): Carbon
    {
        $day = $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;

        return Carbon::createFromFormat('Y-m-d', $day, self::timezone())->startOfDay()->utc();
    }

    /** Date du jour dans le fuseau du jeu, au format Y-m-d. */
    public static function today(): string
    {
        return Carbon::now(self::timezone())->format('Y-m-d');
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
