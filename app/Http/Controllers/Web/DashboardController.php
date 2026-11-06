<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;
use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Models\MineReport;
use App\Models\ProvinceMinePrice;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Services\DecodeScoring;
use App\Services\DecodeWorkflow;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index(): View
    {
        $counts = [
            'users'                => User::count(),
            'users_unverified'     => User::whereNull('email_verified_at')->count(),
            'characters'           => Character::count(),
            'characters_pending'   => self::pendingCharactersCount(),
            'mandates_running'     => MayorMandate::running()->count() + CouncilMandate::running()->count(),
            'mandates_pending'     => MayorMandate::where('status', Mandate::PENDING)->count()
                                    + CouncilMandate::where('status', Mandate::PENDING)->count(),
            'mine_reports_active'  => MineReport::where('active', true)->count(),
            'mine_report_latest'   => MineReport::where('active', true)->max('reported_at'),
            'secrets_open'         => AnimDecodeSecret::where('status', false)->count(),
            'secrets_found_week'   => AnimDecodeProposal::where('points', DecodeScoring::FOUND)
                                        ->where('created_at', '>=', Carbon::now()->subWeek())
                                        ->count(),
            'provinces_with_prices' => ProvinceMinePrice::distinct()->count('province_id'),
        ];

        $expiringMandates   = $this->expiringMandates();
        $inactiveActivities = $this->inactiveActivities();
        $recentActivity     = $this->recentActivity();

        return view('dashboard', compact('counts', 'expiringMandates', 'inactiveActivities', 'recentActivity'));
    }

    public function characters(Request $request): View
    {
        $characters = Character::with(['user', 'city.province.kingdom'])
            ->orderBy('id', 'ASC')->paginate(14);

        return view('characters', compact('characters'));
    }

    public function toggleValidation(Character $character): RedirectResponse
    {
        $character->update([
            'is_validated'             => ! $character->is_validated,
            'pending_residence_change' => false,
        ]);

        return redirect()->route('characters')
            ->with('status', $character->is_validated ? 'character-validated' : 'character-invalidated');
    }

    /**
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function users(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::with('characters')
            ->when($search !== '', function ($query) use ($search) {
                $query->where('email', 'like', "%{$search}%")
                    ->orWhereHas('characters', fn ($q) => $q->where('pseudo', 'like', "%{$search}%"));
            })
            ->orderBy('id', 'ASC')
            ->paginate(14)
            ->withQueryString();

        config([
            'sweetalert.confirm_delete_confirm_button_text' => __('Yes, delete it!'),
            'sweetalert.confirm_delete_cancel_button_text' => __('Cancel'),
        ]);
        confirmDelete(__('Delete this user?'));

        return view('users', ['users' => $users, 'search' => $search]);
    }

    public function destroyUser(User $user, AccountDeletion $deletion): RedirectResponse
    {
        $deletion->deleteUser($user);

        return redirect()->route('users')->with('status', 'user-deleted');
    }

    /** Nombre de personnages non validés — pour la pastille de la sidebar. */
    public static function pendingCharactersCount(): int
    {
        return Character::where('is_validated', false)->count();
    }

    /** Mandats `running` dont `holds_until` tombe dans les 7 prochains jours et sans renouvellement en cours. */
    private function expiringMandates(): Collection
    {
        $soon = [Carbon::now(), Carbon::now()->addDays(7)];

        $mayors = MayorMandate::running()
            ->whereBetween('holds_until', $soon)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('mayor_mandates as r')
                ->whereColumn('r.renews_id', 'mayor_mandates.id')
                ->whereIn('r.status', [Mandate::PENDING, Mandate::APPROVED]))
            ->with(['character', 'city'])
            ->get()
            ->map(fn ($m) => [
                'pseudo'  => $m->character?->pseudo ?? __('dashboard.deleted_character'),
                'type'    => __('dashboard.mandate_mayor'),
                'lieu'    => $m->city?->city_name ?? '?',
                'expires' => $m->holds_until->setTimezone(config('mandates.timezone'))->format('d/m/Y'),
            ]);

        $councils = CouncilMandate::running()
            ->whereBetween('holds_until', $soon)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('council_mandates as r')
                ->whereColumn('r.renews_id', 'council_mandates.id')
                ->whereIn('r.status', [Mandate::PENDING, Mandate::APPROVED]))
            ->with(['character', 'province'])
            ->get()
            ->map(fn ($m) => [
                'pseudo'  => $m->character?->pseudo ?? __('dashboard.deleted_character'),
                'type'    => __('dashboard.mandate_council'),
                'lieu'    => $m->province?->province_name ?? '?',
                'expires' => $m->holds_until->setTimezone(config('mandates.timezone'))->format('d/m/Y'),
            ]);

        return $mayors->concat($councils)->sortBy('expires')->values();
    }

    /** Activités non closes dont la dernière action remonte à plus de 23 jours (7 j avant la fermeture à 30 j). */
    private function inactiveActivities(): Collection
    {
        $workflow = app(DecodeWorkflow::class);

        return AnimDecodeActivity::where('status', '!=', AnimDecodeActivity::CLOSED)
            ->with(['character'])
            ->get()
            ->filter(fn ($a) => $workflow->lastActionAt($a)->lessThan(Carbon::now()->subDays(23)))
            ->map(function ($a) use ($workflow) {
                $daysGone = (int) $workflow->lastActionAt($a)->diffInDays(Carbon::now());

                return [
                    'name'      => $a->name,
                    'organizer' => $a->is_anonymous
                        ? ($a->organizer_name ?? '?')
                        : ($a->character?->pseudo ?? __('dashboard.deleted_character')),
                    'days_left' => AnimDecodeActivity::INACTIVITY_DAYS - $daysGone,
                ];
            })
            ->values();
    }

    /** Les 20 événements les plus récents du site, toutes sources confondues. */
    private function recentActivity(): Collection
    {
        $limit  = 20;
        $events = collect();

        // Nouveaux comptes
        User::latest()->limit($limit)->get()
            ->each(fn ($u) => $events->push([
                'date'  => $u->created_at,
                'icon'  => 'fa-user-plus',
                'color' => 'text-blue-400',
                'text'  => __('dashboard.event_user_created', ['email' => $u->email]),
                'url'   => route('users'),
            ]));

        // Personnages créés
        Character::latest()->limit($limit)->get()
            ->each(fn ($c) => $events->push([
                'date'  => $c->created_at,
                'icon'  => 'fa-user',
                'color' => 'text-green-400',
                'text'  => __('dashboard.event_character_created', ['pseudo' => $c->pseudo]),
                'url'   => route('characters'),
            ]));

        // Demandes de mandat (pending)
        foreach ([
            [MayorMandate::class,   'city',     'city_name',     __('dashboard.mandate_mayor')],
            [CouncilMandate::class, 'province', 'province_name', __('dashboard.mandate_council')],
        ] as [$model, $place, $field, $type]) {
            $model::where('status', Mandate::PENDING)
                ->with(['character', $place])
                ->latest()->limit($limit)->get()
                ->each(fn ($m) => $events->push([
                    'date'  => $m->created_at,
                    'icon'  => 'fa-file-circle-plus',
                    'color' => 'text-indigo-400',
                    'text'  => __('dashboard.event_mandate_pending', [
                        'pseudo' => $m->character?->pseudo ?? __('dashboard.deleted_character'),
                        'type'   => $type,
                        'lieu'   => $m->{$place}?->{$field} ?? '?',
                    ]),
                    'url' => route('mandates.pending'),
                ]));
        }

        // Mandats approuvés (datés de processed_at)
        foreach ([
            [MayorMandate::class,   'city',     'city_name',     __('dashboard.mandate_mayor')],
            [CouncilMandate::class, 'province', 'province_name', __('dashboard.mandate_council')],
        ] as [$model, $place, $field, $type]) {
            $model::where('status', Mandate::APPROVED)
                ->whereNotNull('processed_at')
                ->with(['character', $place])
                ->orderByDesc('processed_at')->limit($limit)->get()
                ->each(fn ($m) => $events->push([
                    'date'  => $m->processed_at,
                    'icon'  => 'fa-circle-check',
                    'color' => 'text-indigo-400',
                    'text'  => __('dashboard.event_mandate_approved', [
                        'pseudo' => $m->character?->pseudo ?? __('dashboard.deleted_character'),
                        'type'   => $type,
                        'lieu'   => $m->{$place}?->{$field} ?? '?',
                    ]),
                    'url' => route('mandates.running'),
                ]));
        }

        // Mandats expirés (approved + holds_until dans le passé, datés de holds_until)
        foreach ([
            [MayorMandate::class,   'city',     'city_name',     __('dashboard.mandate_mayor')],
            [CouncilMandate::class, 'province', 'province_name', __('dashboard.mandate_council')],
        ] as [$model, $place, $field, $type]) {
            $model::where('status', Mandate::APPROVED)
                ->where('holds_until', '<', Carbon::now())
                ->with(['character', $place])
                ->orderByDesc('holds_until')->limit($limit)->get()
                ->each(fn ($m) => $events->push([
                    'date'  => $m->holds_until,
                    'icon'  => 'fa-hourglass-end',
                    'color' => 'text-indigo-400',
                    'text'  => __('dashboard.event_mandate_expired', [
                        'pseudo' => $m->character?->pseudo ?? __('dashboard.deleted_character'),
                        'type'   => $type,
                        'lieu'   => $m->{$place}?->{$field} ?? '?',
                    ]),
                    'url' => route('mandates.decisions'),
                ]));
        }

        // Relevés de mines
        MineReport::with(['province', 'character'])
            ->latest()->limit($limit)->get()
            ->each(fn ($r) => $events->push([
                'date'  => $r->created_at,
                'icon'  => 'fa-file-lines',
                'color' => 'text-amber-400',
                'text'  => __('dashboard.event_mine_report', [
                    'province' => $r->province?->province_name ?? '?',
                    'pseudo'   => $r->character?->pseudo ?? __('dashboard.deleted_character'),
                ]),
                'url' => null,
            ]));

        // Prix de mines mis à jour
        ProvinceMinePrice::with(['province', 'createdBy'])
            ->latest()->limit($limit)->get()
            ->each(fn ($p) => $events->push([
                'date'      => $p->created_at,
                'icon'      => 'crown-coin',
                'icon_type' => 'game',
                'color'     => 'text-amber-400',
                'text'  => __('dashboard.event_mine_price', [
                    'province' => $p->province?->province_name ?? '?',
                    'pseudo'   => $p->createdBy?->pseudo ?? __('dashboard.deleted_character'),
                ]),
                'url' => null,
            ]));

        // Activités créées
        AnimDecodeActivity::with('character')
            ->latest()->limit($limit)->get()
            ->each(fn ($a) => $events->push([
                'date'      => $a->created_at,
                'icon'      => 'rolling-dice-cup',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_activity_created', [
                    'name'      => $a->name,
                    'organizer' => $a->is_anonymous
                        ? ($a->organizer_name ?? '?')
                        : ($a->character?->pseudo ?? __('dashboard.deleted_character')),
                ]),
                'url' => route('anim.index'),
            ]));

        // Activités lancées
        AnimDecodeActivity::whereNotNull('launched_at')
            ->orderByDesc('launched_at')->limit($limit)->get()
            ->each(fn ($a) => $events->push([
                'date'      => $a->launched_at,
                'icon'      => 'rolling-dice-cup',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_activity_launched', ['name' => $a->name]),
                'url'       => route('anim.index'),
            ]));

        // Activités clôturées
        AnimDecodeActivity::whereNotNull('closed_at')
            ->orderByDesc('closed_at')->limit($limit)->get()
            ->each(fn ($a) => $events->push([
                'date'      => $a->closed_at,
                'icon'      => 'rolling-dice-cup',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_activity_closed', ['name' => $a->name]),
                'url'       => route('anim.index'),
            ]));

        // Coffres percés
        AnimDecodeProposal::where('points', DecodeScoring::FOUND)
            ->with('secret.activity')
            ->latest()->limit($limit)->get()
            ->each(fn ($p) => $events->push([
                'date'      => $p->created_at,
                'icon'      => 'rolling-dice-cup',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_secret_found', [
                    'name'      => $p->secret?->activity?->name ?? '?',
                    'character' => $p->character_name ?? '?',
                ]),
                'url' => route('anim.index'),
            ]));

        return $events->filter(fn ($e) => $e['date'] !== null)
            ->sortByDesc('date')
            ->take(20)
            ->values();
    }
}
