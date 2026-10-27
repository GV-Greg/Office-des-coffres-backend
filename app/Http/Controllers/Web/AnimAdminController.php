<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\MandateRefusal;
use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use App\Services\DecodePasswordReset;
use App\Services\DecodeWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Module Animation — administration (synthèse du tour 12, D13, D32, D36, D37). Les types d'activité (activer / désactiver) et
 * la vue de SURVEILLANCE des activités récentes, plus le RECOURS de l'administration pour les activités SANS COMPTE
 * uniquement : clore, retirer (avec une étape de confirmation), émettre un lien de réinitialisation du mot de passe. Les
 * activités privées appartiennent à leur organisateur : aucun geste d'ici.
 *
 * L'administrateur ne connaît JAMAIS un mot de passe. Le lien de réinitialisation qu'il émet est montré UNE SEULE fois (message
 * flash) et se remet au personnage dans le jeu ; le site n'envoie rien (D36).
 */
class AnimAdminController extends Controller
{
    /** Activités affichées dans la vue de surveillance. */
    private const RECENT = 50;

    public function index(): View
    {
        return view('anim.index', [
            'types' => AnimActivityType::orderBy('id')->get(),
            'activities' => AnimDecodeActivity::with(['character', 'type', 'kingdom', 'province', 'city'])
                ->latest('id')
                ->limit(self::RECENT)
                ->get(),
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

    /** Clôture DÉFINITIVE d'une activité sans compte (photo figée, effacements, compte rendu à l'adresse confirmée). */
    public function close(AnimDecodeActivity $activity, DecodeWorkflow $workflow): RedirectResponse
    {
        if ($refusal = $this->anonymousOnly($activity)) {
            return $refusal;
        }

        $workflow->closeNow($activity);

        return redirect()->route('anim.index')->with('status', __('anim.admin.closed_flash', ['name' => $activity->name]));
    }

    /** Étape de confirmation : jamais de suppression en un clic (D13). */
    public function confirmDelete(AnimDecodeActivity $activity): View|RedirectResponse
    {
        return $this->anonymousOnly($activity) ?? view('anim.confirm-delete', ['activity' => $activity]);
    }

    public function destroy(AnimDecodeActivity $activity, DecodeWorkflow $workflow): RedirectResponse
    {
        if ($refusal = $this->anonymousOnly($activity)) {
            return $refusal;
        }

        $workflow->deleteByAdmin($activity);

        return redirect()->route('anim.index')->with('status', __('anim.admin.deleted_flash', ['name' => $activity->name]));
    }

    /** Émet le lien à usage unique et le montre une seule fois, sous forme d'un message prêt à envoyer au personnage dans le jeu. */
    public function resetLink(AnimDecodeActivity $activity, DecodePasswordReset $reset): RedirectResponse
    {
        if ($refusal = $this->anonymousOnly($activity)) {
            return $refusal;
        }

        try {
            $issued = $reset->issueForAdmin($activity);
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
                'url' => $issued['url'],
            ]),
        ]);
    }

    private function anonymousOnly(AnimDecodeActivity $activity): ?RedirectResponse
    {
        return $activity->is_anonymous
            ? null
            : redirect()->route('anim.index')->withErrors(['activity' => __('anim.admin.not_anonymous')]);
    }
}
