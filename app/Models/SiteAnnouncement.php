<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Annonce du site, typiquement une maintenance à venir (18/11/2026, `docs/ARCHITECTURE.md`,
 * « Annonces du site »). Texte FR et EN, échéance de la maintenance, fenêtre de visibilité.
 *
 * Dates stockées en UTC ; l'administrateur les saisit et les relit en heure de Paris (TIMEZONE).
 *
 * Échéance (`schedule_type`) : `exact` = une date et une heure (et, si on veut, une heure de fin) ;
 * `period` = une période en JOURS (« cette semaine ») ; null = rien de précis.
 * Le message peut contenir `{when}` (ou `{quand}`) : il est remplacé, dans la langue demandée, par la
 * date ou la période (« le 24 novembre à 22 h 00 », « cette semaine », « du 24 au 30 novembre »).
 */
class SiteAnnouncement extends Model
{
    public const TIMEZONE = 'Europe/Paris';

    /** Le bandeau défile sur une ligne : un message court. */
    public const MESSAGE_MAX = 500;

    public const EXACT = 'exact';

    public const PERIOD = 'period';

    /** Format d'un champ `datetime-local` (sans fuseau, heure de Paris). */
    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    /** Format d'un champ `date`. */
    public const DATE_FORMAT = 'Y-m-d';

    /** Marqueurs remplacés par l'échéance dans le message. */
    public const PLACEHOLDERS = ['{when}', '{quand}'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'maintenance_starts_at' => 'datetime',
            'maintenance_ends_at' => 'datetime',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
    }

    /**
     * Annonces visibles à `$now` : activées, commencées, et pas terminées. Une annonce se termine à
     * `visible_until` ; à défaut à la fin prévue de la maintenance ; à défaut à son début (une fois la
     * maintenance commencée, le site est fermé : plus rien à annoncer). Sans aucune échéance, elle reste
     * jusqu'à ce que l'administrateur la retire.
     */
    public function scopeVisibleAt(Builder $query, CarbonInterface $now): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('visible_from', '<=', $now)
            ->where(function (Builder $q) use ($now) {
                $q->where('visible_until', '>', $now)
                    ->orWhere(function (Builder $q) use ($now) {
                        $q->whereNull('visible_until')->where(function (Builder $q) use ($now) {
                            $q->where('maintenance_ends_at', '>', $now)
                                ->orWhere(function (Builder $q) use ($now) {
                                    $q->whereNull('maintenance_ends_at')
                                        ->where(fn (Builder $q) => $q->whereNull('maintenance_starts_at')->orWhere('maintenance_starts_at', '>', $now));
                                });
                        });
                    });
            });
    }

    /**
     * L'annonce à afficher : une seule. La maintenance la plus proche d'abord (une annonce sans date de
     * maintenance passe après), puis la plus récente.
     */
    public static function current(?CarbonInterface $now = null): ?self
    {
        return static::query()
            ->visibleAt($now ?? Carbon::now())
            ->orderByRaw('maintenance_starts_at is null')
            ->orderBy('maintenance_starts_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Fin de visibilité effective (null : jusqu'au retrait manuel). */
    public function effectiveEnd(): ?CarbonInterface
    {
        return $this->visible_until ?? $this->maintenance_ends_at ?? $this->maintenance_starts_at;
    }

    /** `off` retirée à la main · `scheduled` pas encore visible · `expired` terminée · `visible`. */
    public function statusAt(CarbonInterface $now): string
    {
        if (! $this->is_active) {
            return 'off';
        }
        if ($this->visible_from->gt($now)) {
            return 'scheduled';
        }
        $end = $this->effectiveEnd();

        return $end !== null && $end->lte($now) ? 'expired' : 'visible';
    }

    // ─── Message et échéance ────────────────────────────────────────────────────────────────────

    /** Le message dans la langue demandée, `{when}` / `{quand}` remplacés par l'échéance. */
    public function renderMessage(string $lang, CarbonInterface $now): string
    {
        $message = $lang === 'en' ? $this->message_en : $this->message_fr;
        $when = $this->whenPhrase($lang, $now);

        return $when === null ? $message : str_replace(self::PLACEHOLDERS, $when, $message);
    }

    /** « le 24 novembre à 22 h 00 », « cette semaine »… (null : aucune échéance). */
    public function whenPhrase(string $lang, CarbonInterface $now): ?string
    {
        $lang = $lang === 'en' ? 'en' : 'fr';
        $start = $this->maintenance_starts_at ? $this->paris($this->maintenance_starts_at, $lang) : null;
        $end = $this->maintenance_ends_at ? $this->paris($this->maintenance_ends_at, $lang) : null;
        if ($start === null) {
            return null;
        }

        $t = fn (string $key, array $replace = []) => (string) trans('announcements.when.'.$key, $replace, $lang);
        $date = fn (Carbon $d) => $d->isoFormat((string) trans('announcements.when.date_format', [], $lang));
        $time = fn (Carbon $d) => $d->isoFormat((string) trans('announcements.when.time_format', [], $lang));

        if ($this->schedule_type === self::PERIOD) {
            $end ??= $start->copy()->endOfDay();
            if ($start->isSameDay($end)) {
                return $t('day', ['date' => $date($start)]);
            }
            $week = $this->paris($now, $lang)->startOfWeek(Carbon::MONDAY);
            if ($start->isSameDay($week) && $end->isSameDay($week->copy()->endOfWeek(Carbon::SUNDAY))) {
                return $t('this_week');
            }
            $next = $week->copy()->addWeek();
            if ($start->isSameDay($next) && $end->isSameDay($next->copy()->endOfWeek(Carbon::SUNDAY))) {
                return $t('next_week');
            }

            return $t('period', ['from' => $date($start), 'to' => $date($end)]);
        }

        if ($end === null) {
            return $t('exact', ['date' => $date($start), 'time' => $time($start)]);
        }
        if ($start->isSameDay($end)) {
            return $t('exact_range', ['date' => $date($start), 'from' => $time($start), 'to' => $time($end)]);
        }

        return $t('exact_days', ['from' => $date($start), 'from_time' => $time($start), 'to' => $date($end), 'to_time' => $time($end)]);
    }

    // ─── Saisie de l'administrateur (heure de Paris) ────────────────────────────────────────────

    public static function parisDateTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::createFromFormat(self::DATETIME_FORMAT, $value, self::TIMEZONE)->utc();
    }

    /** Premier jour d'une période : 00:00 à Paris, en UTC. */
    public static function parisDayStart(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::createFromFormat(self::DATE_FORMAT, $value, self::TIMEZONE)->startOfDay()->utc();
    }

    /** Dernier jour d'une période : 23:59:59 à Paris, en UTC. */
    public static function parisDayEnd(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::createFromFormat(self::DATE_FORMAT, $value, self::TIMEZONE)->endOfDay()->utc();
    }

    /** Valeur d'un champ `datetime-local` / `date` relue en heure de Paris ('' si absent). */
    public static function toParisInput(?CarbonInterface $date, string $format = self::DATETIME_FORMAT): string
    {
        return $date === null ? '' : Carbon::instance($date)->setTimezone(self::TIMEZONE)->format($format);
    }

    private function paris(CarbonInterface $date, string $lang): Carbon
    {
        return Carbon::instance($date)->setTimezone(self::TIMEZONE)->locale($lang);
    }
}
