<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Models\Kingdom;
use Illuminate\Http\JsonResponse;

class MapController extends BaseController
{
    public function index(): JsonResponse
    {
        $kingdoms = Kingdom::with(['provinces' => function ($query) {
            $query->with(['cities' => function ($cities) {
                $cities->orderByDesc('is_capital')->orderBy('city_name');
            }])->orderBy('province_name');
        }])->orderBy('kingdom_name')->get([
            'id', 'kingdom_name',
        ]);

        return response()->json([
            'success' => true,
            'kingdoms' => $kingdoms,
        ]);
    }
}
