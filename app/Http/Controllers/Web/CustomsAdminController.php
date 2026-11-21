<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CustomsOfficer;
use App\Services\CustomsOfficers;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Niveau 3 de la cascade de validation des douaniers : l'administrateur, « sur demande » (brief admin/content/brief-module-securite.md
 * §4.1). Dernier recours quand ni le trio ni le niveau 2 ne peuvent trancher. Même service que l'API : App\Services\CustomsOfficers.
 */
class CustomsAdminController extends Controller
{
    public function __construct(private readonly CustomsOfficers $officers) {}

    public function index(): View
    {
        $load = fn (string $status) => CustomsOfficer::query()->where('status', $status)
            ->with('character', 'province', 'city')->orderBy('created_at')->get();

        return view('customs.index', [
            'pending' => $load(CustomsOfficer::PENDING),
            'approved' => $load(CustomsOfficer::APPROVED),
            'officers' => $this->officers,
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        $this->officers->decideAsAdmin(CustomsOfficer::findOrFail($id), true);

        return back()->with('status', __('security.admin.approved'));
    }

    public function reject(int $id): RedirectResponse
    {
        $this->officers->decideAsAdmin(CustomsOfficer::findOrFail($id), false);

        return back()->with('status', __('security.admin.rejected'));
    }

    public function revoke(int $id): RedirectResponse
    {
        $this->officers->revoke(CustomsOfficer::findOrFail($id), null);

        return back()->with('status', __('security.admin.revoked'));
    }
}
