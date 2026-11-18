<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SiteAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Annonces du site, page d'administration (18/11/2026). Texte FR et EN, échéance de la maintenance (date et
 * heure précises OU période en jours) et fenêtre de visibilité du bandeau ; heures saisies et relues à Paris,
 * stockées en UTC.
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('announcements.index', [
            'announcements' => SiteAnnouncement::query()->orderByDesc('id')->paginate(20),
            'now' => Carbon::now(),
            'announcement' => new SiteAnnouncement(['visible_from' => Carbon::now(), 'is_active' => true]),
        ]);
    }

    public function edit(SiteAnnouncement $announcement): View
    {
        return view('announcements.edit', compact('announcement'));
    }

    public function store(Request $request): RedirectResponse
    {
        SiteAnnouncement::create($this->validated($request));

        return redirect()->route('announcements.index')->with('status', __('announcements.created'));
    }

    public function update(Request $request, SiteAnnouncement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));

        return redirect()->route('announcements.index')->with('status', __('announcements.updated'));
    }

    /** Retire l'annonce du site, ou la remet — sans la supprimer. */
    public function toggle(SiteAnnouncement $announcement): RedirectResponse
    {
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return back()->with('status', __($announcement->is_active ? 'announcements.enabled' : 'announcements.disabled'));
    }

    public function destroy(SiteAnnouncement $announcement): RedirectResponse
    {
        $announcement->delete();

        return back()->with('status', __('announcements.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $dateTime = ['nullable', 'date_format:'.SiteAnnouncement::DATETIME_FORMAT];
        $day = ['nullable', 'date_format:'.SiteAnnouncement::DATE_FORMAT];
        $type = $request->input('schedule_type');

        $data = $request->validate([
            'message_fr' => ['required', 'string', 'max:'.SiteAnnouncement::MESSAGE_MAX],
            'message_en' => ['required', 'string', 'max:'.SiteAnnouncement::MESSAGE_MAX],
            'schedule_type' => ['nullable', 'in:'.SiteAnnouncement::EXACT.','.SiteAnnouncement::PERIOD],
            // Date précise : un début (obligatoire), une fin (facultative).
            'exact_starts_at' => $type === SiteAnnouncement::EXACT ? ['required', 'date_format:'.SiteAnnouncement::DATETIME_FORMAT] : $dateTime,
            'exact_ends_at' => $dateTime,
            // Période : un premier jour (obligatoire), un dernier (facultatif : le même jour).
            'period_from' => $type === SiteAnnouncement::PERIOD ? ['required', 'date_format:'.SiteAnnouncement::DATE_FORMAT] : $day,
            'period_to' => $day,
            'visible_from' => $dateTime,
            'visible_until' => $dateTime,
        ]);

        if ($type === SiteAnnouncement::EXACT) {
            $startsAt = SiteAnnouncement::parisDateTime($data['exact_starts_at']);
            $endsAt = SiteAnnouncement::parisDateTime($data['exact_ends_at'] ?? null);
            $endField = 'exact_ends_at';
        } elseif ($type === SiteAnnouncement::PERIOD) {
            $startsAt = SiteAnnouncement::parisDayStart($data['period_from']);
            $endsAt = SiteAnnouncement::parisDayEnd($data['period_to'] ?? null) ?? SiteAnnouncement::parisDayEnd($data['period_from']);
            $endField = 'period_to';
        } else {
            $startsAt = $endsAt = null;
            $endField = 'exact_ends_at';
        }
        // Sans date de début de visibilité, l'annonce est visible tout de suite.
        $from = SiteAnnouncement::parisDateTime($data['visible_from'] ?? null) ?? Carbon::now();
        $until = SiteAnnouncement::parisDateTime($data['visible_until'] ?? null);

        $errors = [];
        if ($startsAt && $endsAt && $endsAt->lte($startsAt)) {
            $errors[$endField] = __('announcements.ends_before_starts');
        }
        if ($until && $until->lte($from)) {
            $errors['visible_until'] = __('announcements.until_before_from');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'message_fr' => trim($data['message_fr']),
            'message_en' => trim($data['message_en']),
            'schedule_type' => $type ?: null,
            'maintenance_starts_at' => $startsAt,
            'maintenance_ends_at' => $endsAt,
            'visible_from' => $from,
            'visible_until' => $until,
        ];
    }
}
