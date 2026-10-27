<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AnimActivityType;
use App\Models\AnimDecodeActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Module Animation — administration (brief admin/modules/animation/brief-coffres-scelles.md, section
 * « Administration »). Deux surfaces seulement : activer ou désactiver un type d'activité, et une vue
 * de SURVEILLANCE des activités récentes (qui organise quoi), pour vérifier leur légitimité sur le
 * forum. Aucune gestion des activités des joueurs ici : elles appartiennent à leur organisateur.
 */
class AnimAdminController extends Controller
{
    /** Activités affichées dans la vue de surveillance. */
    private const RECENT = 50;

    public function index(): View
    {
        return view('anim.index', [
            'types' => AnimActivityType::orderBy('id')->get(),
            'activities' => AnimDecodeActivity::with(['character', 'type', 'province', 'city'])
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
}
