<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AnimDecodeActivity;
use App\Models\AnimDecodeProposal;
use App\Models\AnimDecodeSecret;
use App\Models\AnimRewardsGrid;
use App\Models\AnimRewardsList;
use App\Models\Character;
use App\Models\CouncilMandate;
use App\Models\Mandate;
use App\Models\MayorMandate;
use App\Models\MineReport;
use App\Models\ProvinceMinePrice;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Services\DecodeScoring;
use App\Support\AnimKinds;
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
        // Bandeau de stats : un groupe par cellule (Comptes, Personnages, Mandats, Économie, Animation ; Sécurité est réservée).
        $counts = [
            'users' => [
                'total'      => User::count(),
                'unverified' => User::whereNull('email_verified_at')->count(),
            ],
            'characters' => [
                'total'   => Character::count(),
                'pending' => self::pendingCharactersCount(),
            ],
            'mandates' => [
                'running' => MayorMandate::running()->count() + CouncilMandate::running()->count(),
                'pending' => MayorMandate::where('status', Mandate::PENDING)->count()
                           + CouncilMandate::where('status', Mandate::PENDING)->count(),
            ],
            'economy' => [
                'reports'   => MineReport::where('active', true)->count(),
                'provinces' => ProvinceMinePrice::distinct()->count('province_id'),
                'latest'    => MineReport::where('active', true)->max('reported_at'),
            ],
            'animation' => [
                'secrets' => AnimDecodeSecret::where('status', false)->count(),
                'grids'   => AnimRewardsGrid::where('status', '!=', AnimRewardsGrid::CLOSED)->count(),
            ],
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

    /**
     * Mandats `running` qui arrivent à leur fin dans les 7 prochains jours, sans renouvellement en cours : la fin effective pour un
     * maire, le TERME pour un conseiller (après lui, il attend son successeur).
     */
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
            ->whereBetween('valid_until', $soon)
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
                'expires' => $m->valid_until->setTimezone(config('mandates.timezone'))->format('d/m/Y'),
            ]);

        return $mayors->concat($councils)->sortBy('expires')->values();
    }

    /**
     * Activités non closes (tous les types d'`AnimKinds` : Décode le code, Grille des lots) dont la dernière action remonte à plus de
     * 23 jours (7 j avant la fermeture à 30 j, `anim:close-inactive`).
     */
    private function inactiveActivities(): Collection
    {
        $alerts = collect();

        foreach (AnimKinds::all() as $kind) {
            $model    = $kind['model'];
            $workflow = app($kind['workflow']);

            $model::where('status', '!=', $model::CLOSED)
                ->with(['character'])
                ->get()
                ->filter(fn ($a) => $workflow->lastActionAt($a)->lessThan(Carbon::now()->subDays(23)))
                ->each(function ($a) use ($workflow, $model, $alerts) {
                    $daysGone = (int) $workflow->lastActionAt($a)->diffInDays(Carbon::now());

                    $alerts->push([
                        'name'      => $a->name,
                        'organizer' => $this->organizerName($a),
                        'days_left' => $model::INACTIVITY_DAYS - $daysGone,
                    ]);
                });
        }

        return $alerts->sortBy('days_left')->values();
    }

    /** Organisateur d'une activité d'Animation : nom libre (sans compte) ou pseudo du personnage. */
    private function organizerName(AnimDecodeActivity|AnimRewardsGrid $activity): string
    {
        return $activity->is_anonymous
            ? ($activity->organizer_name ?? '?')
            : ($activity->character?->pseudo ?? __('dashboard.deleted_character'));
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
                    'organizer' => $this->organizerName($a),
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

        // Grilles des lots : créée, tirée, clôturée. Le tirage mène directement à `confirmed` (jamais `drawed`) et pose
        // `drawn_at` et `confirmed_at` ensemble : `drawn_at` suffit.
        AnimRewardsGrid::with('character')
            ->latest()->limit($limit)->get()
            ->each(fn ($g) => $events->push([
                'date'      => $g->created_at,
                'icon'      => 'podium',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_grid_created', [
                    'name'      => $g->name,
                    'organizer' => $this->organizerName($g),
                ]),
                'url' => route('anim.index'),
            ]));

        AnimRewardsGrid::whereNotNull('drawn_at')
            ->orderByDesc('drawn_at')->limit($limit)->get()
            ->each(fn ($g) => $events->push([
                'date'      => $g->drawn_at,
                'icon'      => 'perspective-dice-six-faces-random',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_grid_drawn', ['name' => $g->name]),
                'url'       => route('anim.index'),
            ]));

        AnimRewardsGrid::whereNotNull('closed_at')
            ->orderByDesc('closed_at')->limit($limit)->get()
            ->each(fn ($g) => $events->push([
                'date'      => $g->closed_at,
                'icon'      => 'checkered-flag',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_grid_closed', ['name' => $g->name]),
                'url'       => route('anim.index'),
            ]));

        // Lots attribués (un nom de joueur posé sur la case), datés de `updated_at`
        AnimRewardsList::whereNotNull('player')
            ->with('grid')
            ->orderByDesc('updated_at')->limit($limit)->get()
            ->each(fn ($l) => $events->push([
                'date'      => $l->updated_at,
                'icon'      => 'trophy',
                'icon_type' => 'game',
                'color'     => 'text-teal-400',
                'text'      => __('dashboard.event_prize_awarded', [
                    'prize'  => $l->name,
                    'winner' => $l->player,
                    'grid'   => $l->grid?->name ?? '?',
                ]),
                'url' => route('anim.index'),
            ]));

        return $events->filter(fn ($e) => $e['date'] !== null)
            ->sortByDesc('date')
            ->take(20)
            ->values();
    }
}
