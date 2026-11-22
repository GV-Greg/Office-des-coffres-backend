<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CustomsOfficer;
use App\Services\CustomsOfficers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
        return view('customs.index', $this->data());
    }

    /**
     * Retrait d'un pseudo du Registre de douane (commande `customs:remove-pseudo`, outil interne hors politique ; Greg, 22/11/2026 : « si quelqu'un
     * m'écrit, j'utiliserai l'outil »). 🔴 Cette page n'est PAS une consultation : elle ne montre que des NOMBRES (entrées, bilans, occurrences),
     * jamais un contenu, une province, une couleur ni un auteur. Elle passe par la commande (Artisan) et ne référence aucune table ni classe des
     * listes et des bilans : SecurityNotConsultableTest le garde. Le pseudo retiré n'est JAMAIS journalisé (le journal le conserverait).
     * Deux temps : simulation, puis confirmation.
     */
    public function previewRemovePseudo(Request $request): View
    {
        $pseudo = $this->pseudo($request);

        return view('customs.index', $this->data() + ['removal' => ['pseudo' => $pseudo] + $this->runRemoval($pseudo, false)]);
    }

    public function removePseudo(Request $request): RedirectResponse
    {
        $counts = $this->runRemoval($this->pseudo($request), true);
        Log::info('Registre de douane : pseudo retiré par l\'administration', ['admin_id' => $request->user()->id] + $counts);

        return redirect()->route('customs.index')->with('status', __('security.admin.pseudo_removed', $counts));
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

    /** Nombre affiché dans le menu de gauche : demandes de douanier en attente (celles que liste la page). */
    public static function pendingCount(): int
    {
        return CustomsOfficer::query()->where('status', CustomsOfficer::PENDING)->count();
    }

    /** @return array{pending: mixed, approved: mixed, officers: CustomsOfficers} */
    private function data(): array
    {
        $load = fn (string $status) => CustomsOfficer::query()->where('status', $status)
            ->with('character', 'province', 'city')->orderBy('created_at')->get();

        return [
            'pending' => $load(CustomsOfficer::PENDING),
            'approved' => $load(CustomsOfficer::APPROVED),
            'officers' => $this->officers,
        ];
    }

    private function pseudo(Request $request): string
    {
        return (string) $request->validate(['pseudo' => ['required', 'string', 'regex:/^\S{1,40}$/u']])['pseudo'];
    }

    /** @return array{entries: int, reports: int, occurrences: int} les NOMBRES touchés, jamais leur contenu */
    private function runRemoval(string $pseudo, bool $apply): array
    {
        $code = Artisan::call('customs:remove-pseudo', ['pseudo' => $pseudo] + ($apply ? ['--force' => true] : []));
        if ($code !== 0 || ! preg_match('/(\d+) entrée\(s\) de liste, (\d+) bilan\(s\), (\d+) occurrence\(s\)/', Artisan::output(), $m)) {
            throw ValidationException::withMessages(['pseudo' => __('security.admin.pseudo_invalid')]);
        }

        return ['entries' => (int) $m[1], 'reports' => (int) $m[2], 'occurrences' => (int) $m[3]];
    }
}
