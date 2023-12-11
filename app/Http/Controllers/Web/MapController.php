<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\BaseController;
use App\Models\Kingdom;
use Illuminate\Contracts\View\View;

class MapController extends BaseController
{
    public function index(): View
    {
        $kingdoms = Kingdom::with(['provinces' => ['cities']])->get();

        return view('map.list', compact('kingdoms'));
    }
}
