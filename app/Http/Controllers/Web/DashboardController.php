<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application
     */
    public function index()
    {
        $userCharacters = User::has('Characters')
            ->with('Characters')
            ->with('Characters.city')
            ->with('Characters.city.province')
            ->with('Characters.city.province.kingdom')
            ->orderBy('id','ASC')->paginate(14);

        return view('dashboard', compact('userCharacters'));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Foundation\Application|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
     */
    public function users()
    {
        $users = User::doesntHave('Characters')->orderBy('id','ASC')->paginate(14);

        return view('users', compact('users'));
    }
}
