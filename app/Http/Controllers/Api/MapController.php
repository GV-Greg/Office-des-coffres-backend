<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Support\MapTree;
use Illuminate\Http\JsonResponse;

class MapController extends BaseController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'kingdoms' => MapTree::get(),
        ]);
    }
}
