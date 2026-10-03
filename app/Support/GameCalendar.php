<?php

namespace App\Support;

/**
 * Calendrier du jeu : le jour et le mois sont ceux du calendrier courant, l'année est celle du jeu
 * (2026 → 1474).
 *
 * ⚠️ JUMEAU de `src/modules/gameCalendar.js` (dépôt frontend) — décision du fil
 * `admin/echanges/mandats-historique` (05, issue (a) ; 10). Les deux copies doivent rester
 * IDENTIQUES, table d'ancrages et logique. Chaque dépôt a un test qui fige la table ET une poignée
 * de conversions calculées (`tests/Unit/GameCalendarTwinTest.php` ici) : qui modifie l'une casse
 * son propre test, dont le message dit de modifier l'autre.
 *
 * Usage côté serveur : les textes que le backend rédige (emails). L'API n'envoie que des dates
 * réelles ; le frontend convertit lui-même (Q15).
 */
class GameCalendar
{
    /**
     * En dessous de cette année, une date est déjà exprimée dans le calendrier du jeu (l'écart
     * dépasse cinq siècles : aucune date réelle manipulée par l'application ne tombe sous ce seuil).
     */
    public const REAL_YEAR_FLOOR = 1900;

    /** Correspondances constatées, année réelle → année du jeu. Même ordre que le jumeau JS. */
    public const YEAR_ANCHORS = [
        ['real' => 2025, 'game' => 1473],
        ['real' => 2026, 'game' => 1474],
    ];

    /**
     * Année du jeu d'une année réelle. Hors des ancrages connus, prolonge l'écart de l'ancrage le
     * plus proche (au premier trouvé en cas d'égalité, comme `reduce` côté JS).
     */
    public static function gameYear(int $realYear): int
    {
        foreach (self::YEAR_ANCHORS as $anchor) {
            if ($anchor['real'] === $realYear) {
                return $anchor['game'];
            }
        }

        $nearest = self::nearest(fn (array $anchor) => abs($anchor['real'] - $realYear));

        return $realYear - ($nearest['real'] - $nearest['game']);
    }

    /** Inverse de gameYear(). Idempotente : une année déjà réelle est renvoyée telle quelle. */
    public static function realYear(int $year): int
    {
        if ($year >= self::REAL_YEAR_FLOOR) {
            return $year;
        }

        foreach (self::YEAR_ANCHORS as $anchor) {
            if ($anchor['game'] === $year) {
                return $anchor['real'];
            }
        }

        $nearest = self::nearest(fn (array $anchor) => abs($anchor['game'] - $year));

        return $year + ($nearest['real'] - $nearest['game']);
    }

    /** @param  callable(array{real: int, game: int}): int  $distance */
    private static function nearest(callable $distance): array
    {
        $best = self::YEAR_ANCHORS[0];
        foreach (self::YEAR_ANCHORS as $anchor) {
            if ($distance($anchor) < $distance($best)) {
                $best = $anchor;
            }
        }

        return $best;
    }
}
