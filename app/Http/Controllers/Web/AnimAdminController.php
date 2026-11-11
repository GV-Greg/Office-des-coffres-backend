<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\MandateRefusal;
use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Models\AnimRewardsGrid;
use App\Support\AnimKinds;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Module Animation — administration (synthèse du tour 12, D13, D32, D36, D37), COMMUNE à toutes les activités (Décode le code,
 * Grille des lots… : `AnimKinds`). Les types d'activité (activer / désactiver) et la vue de SURVEILLANCE des activités récentes
 * de tous les types, plus le RECOURS de l'administrateur du site pour les activités SANS COMPTE uniquement : clore, retirer (avec
 * une étape de confirmation), émettre un lien de réinitialisation du mot de passe. Les activités privées appartiennent à leur
 * organisateur : aucun geste d'ici.
 *
 * Une activité s'adresse par `{kind}/{id}` (la clé du type, puis son identifiant) : les identifiants de deux types peuvent coïncider.
 *
 * L'administrateur du site ne connaît JAMAIS un mot de passe. Le lien de réinitialisation qu'il émet est montré UNE SEULE fois (message
 * flash) et se remet au personnage dans le jeu ; avec une adresse confirmée, l'auteur le reçoit lui-même par email (D36).
 */
class AnimAdminController extends Controller
{
    /** Activités affichées dans la vue de surveillance (tous types confondus). */
    private const RECENT = 50;

    public function index(): View
    {
        $activities = collect(AnimKinds::all())
            ->flatMap(fn (array $kind) => $kind['model']::with(['character', 'type', 'kingdom', 'province', 'city'])
                ->latest('id')
                ->limit(self::RECENT)
                ->get()
                ->each(fn ($activity) => $activity->setAttribute('anim_kind', $kind['key'])))
            ->sortByDesc('created_at')
            ->take(self::RECENT)
            ->values();

        return view('anim.index', [
            'types' => AnimActivityType::orderBy('id')->get(),
            'activities' => $activities,
        ]);
    }

    public function toggleType(AnimActivityType $type): RedirectResponse
    {
        $type->update(['is_active' => ! $type->is_active]);

        return redirect()->route('anim.index')->with('status', __('anim.admin.toggled', [
            'type' => __("anim.types.{$type->key}.name"),
            'state' => __($type->is_active ? 'anim.admin.active' : 'anim.admin.inactive'),
        ]));
    }

    /** Clôture DÉFINITIVE d'une activité sans compte (photo figée, effacements, compte rendu à l'adresse confirmée si le type en envoie un). */
    public function close(string $kind, int $id): RedirectResponse
    {
        [$entry, $activity] = $this->resolve($kind, $id);
        if ($refusal = $this->anonymousOnly($activity)) {
            return $refusal;
        }

        app($entry['workflow'])->closeNow($activity);

        return redirect()->route('anim.index')->with('status', __('anim.admin.closed_flash', ['name' => $activity->name]));
    }

    /** Étape de confirmation : jamais de suppression en un clic (D13). */
    public function confirmDelete(string $kind, int $id): View|RedirectResponse
    {
        [, $activity] = $this->resolve($kind, $id);

        return $this->anonymousOnly($activity) ?? view('anim.confirm-delete', ['activity' => $activity, 'kind' => $kind]);
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        [$entry, $activity] = $this->resolve($kind, $id);
        if ($refusal = $this->anonymousOnly($activity)) {
            return $refusal;
        }

        app($entry['workflow'])->deleteByAdmin($activity);

        return redirect()->route('anim.index')->with('status', __('anim.admin.deleted_flash', ['name' => $activity->name]));
    }

    /** Émet le lien à usage unique et le montre une seule fois, sous forme d'un message prêt à envoyer au personnage dans le jeu. */
    public function resetLink(string $kind, int $id): RedirectResponse
    {
        [$entry, $activity] = $this->resolve($kind, $id);
        if ($refusal = $this->anonymousOnly($activity)) {
            return $refusal;
        }

        try {
            $issued = app($entry['reset'])->issueForAdmin($activity);
        } catch (MandateRefusal $e) {
            return redirect()->route('anim.index')->withErrors($e->errors());
        }

        return redirect()->route('anim.index')->with('reset_link', [
            'heading' => __('anim.admin.reset_link_flash', [
                'name' => $activity->name,
                'pseudo' => $activity->organizer_name,
                'until' => $issued['expires_at']->copy()->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i'),
            ]),
            'message' => __('anim.admin.reset_message', [
                'pseudo' => $activity->organizer_name,
                'name' => $activity->name,
                'type' => __("anim.types.{$entry['key']}.name"),
                'url' => $issued['url'],
            ]),
        ]);
    }

    /**
     * Le type (404 s'il est inconnu) et l'activité (404 si elle n'existe pas).
     *
     * @return array{0: array<string, mixed>, 1: AnimDecodeActivity|AnimRewardsGrid}
     */
    private function resolve(string $kind, int $id): array
    {
        $entry = AnimKinds::find($kind) ?? abort(404);

        return [$entry, $entry['model']::findOrFail($id)];
    }

    private function anonymousOnly(AnimDecodeActivity|AnimRewardsGrid $activity): ?RedirectResponse
    {
        return $activity->is_anonymous
            ? null
            : redirect()->route('anim.index')->withErrors(['activity' => __('anim.admin.not_anonymous')]);
    }
}
