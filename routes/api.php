<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\MapController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('map', [MapController::class, 'index']);

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login',    [AuthController::class, 'login']);
        Route::post('refresh',  [AuthController::class, 'refresh']);

        Route::post('resend-verification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');

        Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify.api');

        Route::middleware('auth:api')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me',      [AuthController::class, 'me']);
            // Suppression self-service (art. 17 RGPD) : toujours le compte du porteur du jeton,
            // jamais un id passé en paramètre.
            Route::delete('account', [AuthController::class, 'destroyAccount']);
        });
    });

    Route::middleware('auth:api')->group(function () {
        Route::get('characters',  [CharacterController::class, 'index']);
        Route::post('characters', [CharacterController::class, 'store']);
        Route::patch('characters/{character}', [CharacterController::class, 'update']);
    });

});
