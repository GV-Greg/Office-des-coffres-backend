<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Lecture du journal des modifications de la politique (resources/policy/changelog.php), source
 * unique de `policy:notify` (fil admin/echanges/politique-promesses, Q6).
 *
 * Les entrées peuvent être injectées (tests) ; sinon le fichier du dépôt fait foi.
 */
class PolicyChangelog
{
    /** @var array<string, array{date: string, summary: array{fr: string, en: string}, substantial: bool, decided_by: string}> */
    private array $entries;

    public function __construct(?array $entries = null)
    {
        $this->entries = $entries ?? require resource_path('policy/changelog.php');
    }

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->entries;
    }

    public function find(string $id): ?array
    {
        return $this->entries[$id] ?? null;
    }

    public static function date(array $entry): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $entry['date']);
    }

    /**
     * Défauts de forme d'une entrée — une entrée mal formée partirait chez tous les comptes
     * (clé brute, résumé vide, date illisible). Liste vide = entrée valide.
     *
     * @return array<int, string>
     */
    public static function problems(array $entry): array
    {
        $problems = [];

        if (! is_string($entry['date'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry['date'])
            || Carbon::createFromFormat('!Y-m-d', $entry['date'])->format('Y-m-d') !== $entry['date']) {
            $problems[] = 'date';
        }
        foreach (['fr', 'en'] as $locale) {
            if (! is_string($entry['summary'][$locale] ?? null) || trim($entry['summary'][$locale]) === '') {
                $problems[] = "summary.{$locale}";
            }
        }
        if (! is_bool($entry['substantial'] ?? null)) {
            $problems[] = 'substantial';
        }
        if (! is_string($entry['decided_by'] ?? null) || trim($entry['decided_by']) === '') {
            $problems[] = 'decided_by';
        }

        return $problems;
    }
}
