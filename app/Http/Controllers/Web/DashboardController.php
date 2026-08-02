<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    /**
     * @return \Illuminate\Contracts\Foundation\Application|Factory|View|\Illuminate\Contracts\Foundation\Application
     */
    public function index()
    {
        $characters = Character::with(['user', 'city.province.kingdom'])
            ->orderBy('id', 'ASC')->paginate(14);

        return view('dashboard', compact('characters'));
    }

    public function toggleValidation(Character $character): RedirectResponse
    {
        $character->update(['is_validated' => ! $character->is_validated]);

        return redirect()->route('dashboard')
            ->with('status', $character->is_validated ? 'character-validated' : 'character-invalidated');
    }

    /**
     * @return Factory|Application|View|\Illuminate\Contracts\Foundation\Application
     */
    public function users()
    {
        $users = User::doesntHave('Characters')->orderBy('id', 'ASC')->paginate(14);

        config([
            'sweetalert.confirm_delete_confirm_button_text' => __('Yes, delete it!'),
            'sweetalert.confirm_delete_cancel_button_text' => __('Cancel'),
        ]);
        confirmDelete(__('Delete this user?'));

        return view('users', compact('users'));
    }

    public function destroyUser(User $user): RedirectResponse
    {
        $user->delete();

        return redirect()->route('users')->with('status', 'user-deleted');
    }
}
