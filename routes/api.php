<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\MandateController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MinePriceController;
use App\Http\Controllers\Api\MineReportController;
use App\Http\Controllers\Api\ProvinceHistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('map', [MapController::class, 'index']);

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:register');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:login');
        Route::post('refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:refresh');

        Route::post('resend-verification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');

        Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify.api');

        Route::middleware('auth:api')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            // Suppression self-service (art. 17 RGPD) : toujours le compte du porteur du jeton,
            // jamais un id passé en paramètre.
            Route::delete('account', [AuthController::class, 'destroyAccount']);
        });
    });

    // Référentiel des postes titrés d'un conseil comtal : public, aucune donnée de compte.
    Route::get('council-offices', [MandateController::class, 'offices']);

    Route::middleware('auth:api')->group(function () {
        Route::get('characters', [CharacterController::class, 'index']);
        Route::post('characters', [CharacterController::class, 'store']);
        Route::patch('characters/{character}', [CharacterController::class, 'update']);

        // Mandats (admin/content/brief-mandats.md, lot 1) : toujours les personnages du porteur
        // du jeton, 404 pour ceux d'un autre compte.
        Route::get('mandates', [MandateController::class, 'index']);
        // « Ma province » : historique des postes de la province de résidence du personnage.
        Route::get('characters/{character}/province', [ProvinceHistoryController::class, 'show'])
            ->where('character', '[0-9]+');
        // Registre des mines : ce que le personnage peut faire maintenant, et dans quelle province.
        Route::get('characters/{character}/mine-registry', [MineReportController::class, 'access'])
            ->where('character', '[0-9]+');
        // Registre des mines : lecture (PR 4) — commissaire aux mines, bailli, dirigeant.
        Route::get('characters/{character}/mine-registry/reports', [MineReportController::class, 'reports'])
            ->where('character', '[0-9]+');
        // Registre des mines, lot 2 — prix par période (brief admin/content/brief-prix-registre.md).
        // Pas de DELETE : un prix faux se corrige. ⚠️ Routes ajoutées : route:cache en prod.
        Route::get('provinces/{province}/mine-prices', [MinePriceController::class, 'index'])
            ->where('province', '[0-9]+');
        Route::get('provinces/{province}/mine-prices/current', [MinePriceController::class, 'current'])
            ->where('province', '[0-9]+');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('provinces/{province}/mine-prices', [MinePriceController::class, 'store'])
                ->where('province', '[0-9]+');
            Route::put('provinces/{province}/mine-prices/{price}', [MinePriceController::class, 'update'])
                ->where(['province' => '[0-9]+', 'price' => '[0-9]+']);
        });
        Route::middleware('throttle:6,1')->group(function () {
            Route::post('characters/{character}/mandates', [MandateController::class, 'store']);
            // Registre des mines — écriture (PR 1b) : toutes les règles dans App\Services\MineRegistry.
            Route::post('characters/{character}/mine-reports', [MineReportController::class, 'store']);
            Route::post('mandates/{level}/{id}/renew', [MandateController::class, 'renew'])
                ->where(['level' => 'mayor|council', 'id' => '[0-9]+']);
            Route::post('mandates/council/{id}/office', [MandateController::class, 'declareOffice'])
                ->where('id', '[0-9]+');
        });
        Route::delete('mandates/{level}/{id}', [MandateController::class, 'destroy'])
            ->where(['level' => 'mayor|council', 'id' => '[0-9]+']);
    });

});
