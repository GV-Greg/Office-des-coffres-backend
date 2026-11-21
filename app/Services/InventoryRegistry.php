<?php

namespace App\Services;

use App\Exceptions\InventoryAccessDenied;
use App\Exceptions\InventoryBeforeMandateLocked;
use App\Exceptions\InventoryReplacementNeedsConfirmation;
use App\Exceptions\MandateRefusal;
use App\Models\Character;
use App\Models\Mandate;
use App\Support\MandateCalendar;
use App\Support\MandateLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registre des stocks — socle des deux registres de TERRITOIRE (fil admin/echanges/registre-stocks, 20/11/2026) : la province
 * (commissaire au commerce) et la ville (maire). SEUL écrivain de son niveau ; une sous-classe dit le modèle, la clé du lieu, le
 * poste qui écrit et celui qui lit. Le niveau personnel n'a PAS de registre (Q4 : reporté).
 *
 * ÉCRITURE : le titulaire en fonction seulement, lieu du POSTE. Date = jour de Paris où l'on colle (le texte du jeu ne porte aucune
 * date). Un second collage le même jour REMPLACE le premier — mais seulement avec `confirm_replace` (409 sinon, Q6) : l'ancien
 * passe `active` NULL avec `replaced_by_id` et reste en base, rien ne s'efface. Un texte identique à celui du jour ne crée rien.
 * LECTURE : un NOUVEAU titulaire voit l'historique COMPLET de ses prédécesseurs (des faits du territoire, pas des biens personnels).
 * AUTEURS (Q5) : jamais nommés dans la liste, sauf le rédacteur REMPLACÉ (`replaced`) et celui de l'exemplaire à remplacer (409) —
 * c'est ce que la politique §3 promet. Les bilans se calculent dans le navigateur : le serveur sert les textes déchiffrés.
 */
abstract class InventoryRegistry
{
    /** Exemplaires servis par lecture (les plus récents). */
    public const HISTORY_LIMIT = 100;

    /**
     * Combien de JOURS DU JEU avant son entrée en fonction un titulaire peut rétro-dater une inscription (fil registre-stocks, brief 10 :
     * l'inventaire hérité du prédécesseur à la prise de poste). Constante, jamais `config/*.php` (cache de configuration de la prod).
     */
    public const BACKDATE_BEFORE_MANDATE_DAYS = 14;

    /** La fenêtre « prédécesseurs » : les 30 jours du jeu avant l'entrée en fonction du lecteur (fiche de prise de poste, comme les mines). */
    public const PREDECESSOR_DAYS = 30;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Colonne du lieu : `province_id` ou `city_id`. */
    abstract protected function placeColumn(): string;

    /** Estampille du poste à l'écriture. */
    abstract protected function officeKey(): string;

    /** Le lieu que le personnage peut ÉCRIRE maintenant, ou null. */
    abstract protected function writablePlace(Character $character): ?Model;

    /** Le lieu que le personnage peut LIRE maintenant, ou null. */
    abstract protected function readablePlace(Character $character): ?Model;

    /** Le mandat qui porte le poste d'ÉCRITURE (sa date d'entrée en fonction borne la rétro-datation), ou null. */
    abstract protected function writableMandate(Character $character): ?Mandate;

    /** Jours du jeu entre l'entrée en fonction du lecteur et le bilan de MI-MANDAT : la moitié du mandat (constante de la sous-classe, jamais de config). */
    abstract protected function midMandateDays(): int;

    /** Jours du jeu entre l'entrée en fonction du lecteur et le bilan de FIN DE MANDAT : la totalité du mandat. */
    abstract protected function endMandateDays(): int;

    /** Le mandat qui porte le poste de LECTURE (sa date d'entrée en fonction : le début du mandat du lecteur), ou null. */
    abstract protected function readerMandate(Character $character): ?Mandate;

    abstract protected function placeName(Model $place): string;

    /** Clé `inventory.api.*` du refus d'écriture. */
    abstract protected function deniedWriteKey(): string;

    /** Clé `inventory.api.*` du refus de lecture. */
    abstract protected function deniedReadKey(): string;

    /**
     * `$date` (Y-m-d, optionnelle) : le jour du jeu sous lequel l'inventaire s'inscrit — absent, c'est le jour du jeu en cours. Plage permise :
     * de 14 jours du jeu AVANT l'entrée en fonction du titulaire jusqu'à aujourd'hui, jamais une date future (422 `date` sinon). Le conflit se
     * cherche à CETTE date : pendant le mandat, un autre texte demande confirmation (409) ; avant le mandat, l'inscription existante ne se
     * remplace jamais (409 `registry_before_mandate_locked`, brief 10).
     *
     * @return array{snapshot: Model, replaced: ?Model, unchanged: bool}
     *
     * @throws InventoryAccessDenied
     * @throws InventoryBeforeMandateLocked
     * @throws InventoryReplacementNeedsConfirmation
     * @throws MandateRefusal
     */
    public function record(Character $character, string $raw, bool $confirmReplace = false, ?string $date = null): array
    {
        $place = $this->writablePlace($character) ?? throw new InventoryAccessDenied($this->deniedWriteKey());
        InventoryText::assertText($raw);
        $mandate = $this->writableMandate($character) ?? throw new InventoryAccessDenied($this->deniedWriteKey());
        $today = MandateCalendar::today();
        $officeFrom = MandateCalendar::gameDayOf($mandate->in_office_from);
        $earliest = MandateCalendar::shiftGameDay($officeFrom, -self::BACKDATE_BEFORE_MANDATE_DAYS);
        $takenOn = $date ?? $today;
        if ($takenOn < $earliest || $takenOn > $today) {
            throw MandateRefusal::make('date', 'inventory.api.registry_date_out_of_range', [
                'min' => Carbon::parse($earliest)->format('d/m/Y'),
                'max' => Carbon::parse($today)->format('d/m/Y'),
            ]);
        }
        $model = $this->model();

        return DB::transaction(function () use ($character, $raw, $place, $takenOn, $officeFrom, $confirmReplace, $model) {
            $existing = $model::query()
                ->where($this->placeColumn(), $place->id)
                ->whereDate('taken_on', $takenOn)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->payload === $raw) {
                return ['snapshot' => $existing, 'replaced' => null, 'unchanged' => true];
            }
            // Avant la prise de fonction : l'inscription en place est celle d'un prédécesseur (ou rétro-datée) — elle ne se remplace pas, même confirmée.
            if ($existing && $takenOn < $officeFrom) {
                throw new InventoryBeforeMandateLocked;
            }
            if ($existing && ! $confirmReplace) {
                throw new InventoryReplacementNeedsConfirmation($existing->loadMissing('character'), $this->placeName($place));
            }
            // L'ancien d'abord : l'index unique (lieu, jour, active) n'accepte pas deux actifs.
            $existing?->update(['active' => null]);

            $snapshot = $model::create([
                $this->placeColumn() => $place->id,
                'taken_on' => $takenOn,
                'character_id' => $character->id,
                'office_key' => $this->officeKey(),
                'payload' => $raw,
            ]);
            $existing?->update(['replaced_by_id' => $snapshot->id, 'replaced_at' => Carbon::now()]);

            return ['snapshot' => $snapshot, 'replaced' => $existing?->loadMissing('character'), 'unchanged' => false];
        });
    }

    /**
     * L'historique du lieu : les exemplaires en vigueur, du plus récent au plus ancien, déchiffrés.
     *
     * `today` : le jour du jeu EN COURS (bascule à 04:00 Paris, MandateCalendar), pour que l'écran dise l'âge de la dernière inscription
     * (« il y a 2 jours, le 24/05 ») comme celui du Registre des mines — sans que le navigateur recalcule la bascule.
     *
     * `mandate` : les repères du mandat DU LECTEUR (« chacun le sien », dirigeant compris), au format du Registre des mines — le jour du jeu où il
     * est entré en fonction, et les dates de son bilan de mi-mandat et de fin de mandat (la moitié et la totalité du mandat : province 30/60,
     * ville 15/30 jours du jeu, fil registre-stocks R7) ; null s'il n'a pas de mandat en fonction. `backdate_min` : la plus ancienne date qu'il
     * peut choisir pour inscrire (null s'il n'écrit pas).
     *
     * @return array{place: array{id: int, name: string}, snapshots: list<array<string, mixed>>, can_write: bool, today: string, mandate: ?array{in_office_from: string, mid_at: string, end_at: string, predecessor_from: string}, backdate_min: ?string}
     *
     * @throws InventoryAccessDenied
     */
    public function history(Character $character): array
    {
        $place = $this->readablePlace($character) ?? throw new InventoryAccessDenied($this->deniedReadKey());
        $model = $this->model();

        $snapshots = $model::query()
            ->where($this->placeColumn(), $place->id)
            ->where('active', true)
            ->with('replaces.character')
            ->orderByDesc('taken_on')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (Model $s) => [
                'id' => $s->id,
                'taken_on' => $s->taken_on->toDateString(),
                'saved_at' => $s->created_at?->toIso8601String(),
                'office_key' => $s->office_key,
                // L'auteur de CET exemplaire n'est jamais nommé : seulement si c'est soi. Le pseudo n'apparaît que pour le rédacteur
                // d'un exemplaire REMPLACÉ (`replaced`), comme la politique §3 le promet.
                'by_me' => $s->character_id === $character->id,
                'replaced' => $s->replaces ? self::author($s->replaces) + ['replaced_at' => $s->replaces->replaced_at?->toIso8601String()] : null,
                'raw' => $s->payload,
            ])
            ->values()
            ->all();

        return [
            'place' => ['id' => $place->id, 'name' => $this->placeName($place)],
            'snapshots' => $snapshots,
            'can_write' => $this->writablePlace($character)?->id === $place->id,
            'today' => (string) MandateCalendar::today(),
            'mandate' => ($reader = $this->readerMandate($character)) ? [
                'in_office_from' => MandateCalendar::gameDayOf($reader->in_office_from),
                'mid_at' => MandateCalendar::gameDayOf(MandateCalendar::addGameDays($reader->in_office_from, $this->midMandateDays())),
                'end_at' => MandateCalendar::gameDayOf(MandateCalendar::addGameDays($reader->in_office_from, $this->endMandateDays())),
                'predecessor_from' => MandateCalendar::gameDayOf(MandateCalendar::addGameDays($reader->in_office_from, -self::PREDECESSOR_DAYS)),
            ] : null,
            'backdate_min' => $this->backdateMin($character),
        ];
    }

    /** La plus ancienne date que le titulaire en fonction peut choisir (Y-m-d), ou null s'il n'écrit pas. */
    private function backdateMin(Character $character): ?string
    {
        $mandate = $this->writableMandate($character);

        return $mandate
            ? MandateCalendar::shiftGameDay(MandateCalendar::gameDayOf($mandate->in_office_from), -self::BACKDATE_BEFORE_MANDATE_DAYS)
            : null;
    }

    /**
     * Qui a écrit un exemplaire — nommé à l'écran quand il est remplacé (Q5). `pseudo` NULL une fois le personnage supprimé.
     *
     * @return array{id: int, pseudo: ?string, office_key: string, office_label: array{fr: string, en: string}, taken_on: string, saved_at: ?string}
     */
    public static function author(Model $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'pseudo' => $snapshot->character?->pseudo,
            'office_key' => $snapshot->office_key,
            'office_label' => ['fr' => MandateLabels::office($snapshot->office_key, 'fr'), 'en' => MandateLabels::office($snapshot->office_key, 'en')],
            'taken_on' => $snapshot->taken_on->toDateString(),
            'saved_at' => $snapshot->created_at?->toIso8601String(),
        ];
    }
}
