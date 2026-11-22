<?php

namespace App\Services;

use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\CustomsWatchlistEntry;
use App\Models\Province;
use App\Support\CustomsPseudo;
use App\Support\MandateCalendar;

/**
 * SEUL écrivain des listes rouge et verte d'une province (Registre de douane ; fil admin/echanges/module-securite, décisions de Greg).
 *
 * - Droits : App\Services\CustomsAccess. Le trio écrit ; maire et douanier SEULEMENT sans trio connu ; le juge n'ajoute qu'au rouge.
 * - L'AUTEUR retire sa propre entrée, même quand le trio est actif (P4). Un retrait SUPPRIME la ligne (choix B, 22/11/2026).
 * - Le pseudo et la raison sont CHIFFRÉS : la comparaison se fait en mémoire, en minuscules (C4), l'affichage garde la casse saisie.
 * - Couleur à partir de `added_on` (jour du jeu de l'ajout), tant que l'entrée est en liste.
 * - « Ajouté par » n'est visible que du trio ; les autres voient `by_me` sur leurs entrées et rien sur celles d'autrui (politique §6).
 */
class CustomsWatchlist
{
    public function __construct(private readonly CustomsAccess $access) {}

    /** @return array<int, array<string, mixed>> */
    public function list(Character $viewer): array
    {
        $access = $this->readAccess($viewer);

        return CustomsWatchlistEntry::query()->where('province_id', $access['province']->id)->with('addedBy')->orderBy('id')->get()
            ->map(fn (CustomsWatchlistEntry $entry) => $this->present($entry, $viewer, $access))->values()->all();
    }

    public function add(Character $actor, string $pseudo, string $colour, ?string $reason): CustomsWatchlistEntry
    {
        $access = $this->readAccess($actor);
        $pseudo = trim($pseudo);

        // Le juge n'ajoute qu'au rouge ; le trio et le repli ajoutent partout.
        $allowed = $access['write_lists'] || ($access['add_red_only'] && $colour === CustomsWatchlistEntry::RED);
        if (! $allowed) {
            throw MandateRefusal::make('colour', 'security.api.lists_not_authorized');
        }

        if ($this->find($access['province'], $pseudo)) {
            throw MandateRefusal::make('pseudo', 'security.api.watchlist_entry_exists');
        }

        return CustomsWatchlistEntry::create([
            'province_id' => $access['province']->id,
            'colour' => $colour,
            'added_on' => MandateCalendar::today(),
            'payload' => ['pseudo' => $pseudo, 'reason' => $this->clean($reason)],
            'added_by_character_id' => $actor->id,
        ]);
    }

    /**
     * Raison et/ou couleur. Changer de couleur repart d'une NOUVELLE date d'ajout (la couleur ne court qu'à partir de l'ajout dans la couleur).
     * Réservé à ceux qui écrivent les listes : ni le juge, ni l'auteur seul (il ne fait que retirer la sienne).
     */
    public function update(Character $actor, int $id, array $changes): CustomsWatchlistEntry
    {
        $access = $this->readAccess($actor);
        $entry = $this->entry($access['province'], $id);
        if (! $access['write_lists']) {
            throw MandateRefusal::make('entry', 'security.api.lists_not_authorized');
        }

        $payload = $entry->payload;
        if (array_key_exists('reason', $changes)) {
            $payload['reason'] = $this->clean($changes['reason']);
        }
        $update = ['payload' => $payload];
        if (isset($changes['colour']) && $changes['colour'] !== $entry->colour) {
            $update['colour'] = $changes['colour'];
            $update['added_on'] = MandateCalendar::today();
        }
        $entry->update($update);

        return $entry->refresh();
    }

    /** Retrait = SUPPRESSION de la ligne. Par ceux qui écrivent les listes, ou par l'auteur de l'entrée. */
    public function remove(Character $actor, int $id): void
    {
        $access = $this->readAccess($actor);
        $entry = $this->entry($access['province'], $id);

        $author = $entry->added_by_character_id !== null && $entry->added_by_character_id === $actor->id;
        if (! $access['write_lists'] && ! $author) {
            throw MandateRefusal::make('entry', 'security.api.lists_not_authorized');
        }

        $entry->delete();
    }

    /**
     * Couleur de chaque pseudo à la date `$day` : clé en minuscules → `red` | `green`, pour les entrées en liste depuis `$day` au plus tard.
     *
     * @return array<string, string>
     */
    public function colourMap(Province $province, string $day): array
    {
        $map = [];
        CustomsWatchlistEntry::query()->where('province_id', $province->id)->whereDate('added_on', '<=', $day)->orderBy('id')->get()
            ->each(function (CustomsWatchlistEntry $entry) use (&$map) {
                $map[CustomsPseudo::key($entry->pseudo())] = $entry->colour;
            });

        return $map;
    }

    /** @return array<string, mixed> */
    private function readAccess(Character $character): array
    {
        $access = $this->access->for($character);
        if (! $access || (! $access['read_lists'] && ! $access['add_red_only'])) {
            throw MandateRefusal::make('character', 'security.api.lists_not_readable');
        }

        return $access;
    }

    private function entry(Province $province, int $id): CustomsWatchlistEntry
    {
        $entry = CustomsWatchlistEntry::query()->where('province_id', $province->id)->find($id);
        if (! $entry) {
            throw MandateRefusal::make('entry', 'security.api.watchlist_entry_not_found');
        }

        return $entry;
    }

    private function find(Province $province, string $pseudo): ?CustomsWatchlistEntry
    {
        $key = CustomsPseudo::key($pseudo);

        return CustomsWatchlistEntry::query()->where('province_id', $province->id)->get()
            ->first(fn (CustomsWatchlistEntry $entry) => CustomsPseudo::key($entry->pseudo()) === $key);
    }

    private function clean(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return $reason === '' ? null : $reason;
    }

    /** @return array<string, mixed> */
    private function present(CustomsWatchlistEntry $entry, Character $viewer, array $access): array
    {
        $mine = $entry->added_by_character_id !== null && $entry->added_by_character_id === $viewer->id;

        return [
            'id' => $entry->id,
            'colour' => $entry->colour,
            'pseudo' => $entry->pseudo(),
            'reason' => $entry->reason(),
            'added_on' => $entry->added_on->toDateString(),
            'by_me' => $mine,
            // Visible du TRIO seulement ; null si le personnage a disparu.
            'added_by' => $access['sees_authors'] ? $entry->addedBy?->pseudo : null,
            'can_remove' => $access['write_lists'] || $mine,
            'can_edit' => $access['write_lists'],
        ];
    }
}
