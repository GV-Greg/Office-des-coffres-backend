<?php

use App\Http\Controllers\Api\AdminCharacterController;
use App\Http\Controllers\Api\Anim\DecodeActivityController;
use App\Http\Controllers\Api\Anim\DecodeAnonymousController;
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

        // Confirmation du changement d'email : lien signé cliqué depuis l'email, sans jeton Passport.
        // ⚠️ Routes ajoutées : route:cache en prod.
        Route::get('account/email/verify/{id}/{hash}', [AuthController::class, 'confirmEmailChange'])
            ->where(['id' => '[0-9]+', 'hash' => '[a-f0-9]{40}'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('account.email.verify');

        Route::middleware('auth:api')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            // Suppression self-service (art. 17 RGPD) : toujours le compte du porteur du jeton,
            // jamais un id passé en paramètre.
            Route::delete('account', [AuthController::class, 'destroyAccount']);
            // Export art. 20 RGPD (portabilité) : toujours le compte du porteur du jeton.
            Route::get('account/export', [AuthController::class, 'exportAccount'])
                ->middleware('throttle:6,1');
            // Changement d'email : stocke le pending_email et envoie un lien de confirmation.
            Route::patch('account/email', [AuthController::class, 'updateEmail'])
                ->middleware('throttle:6,1');
            // Changement de mot de passe : révoque tous les tokens sauf le courant.
            Route::patch('account/password', [AuthController::class, 'updatePassword'])
                ->middleware('throttle:6,1');
        });
    });

    // Référentiel des postes titrés d'un conseil comtal : public, aucune donnée de compte.
    Route::get('council-offices', [MandateController::class, 'offices']);

    // Animation — lecture publique (types ouverts, page d'une activité par son SLUG, les deux modes).
    // 🔴 Aucun mot réservé (D14) : aucune route fixe ne voisine `{slug}` au même niveau. Tout ce qui n'est pas un slug vit
    // sous un préfixe à part (`decode-manage/{slug}/…`, `decode-slug`, `decode-email-confirm/…`).
    // ⚠️ Routes ajoutées : route:cache en prod.
    Route::get('anim/activity-types', [DecodeActivityController::class, 'types']);
    Route::get('anim/decode-activities/{slug}', [DecodeActivityController::class, 'show'])
        ->where('slug', '[a-z-]{1,60}');

    // Animation — mode SANS COMPTE (aucun jeton : le slug public + le mot de passe, dans le corps, prouvent l'auteur).
    Route::get('anim/decode-slug', [DecodeAnonymousController::class, 'slug'])->middleware('throttle:anim-slug');
    Route::post('anim/decode-manage', [DecodeAnonymousController::class, 'create'])->middleware('throttle:anim-create');
    Route::prefix('anim/decode-manage/{slug}')->where(['slug' => '[a-z-]{1,60}', 'secret' => '[0-9]+'])->group(function () {
        Route::middleware('throttle:anim-access')->group(function () {
            Route::post('open', [DecodeAnonymousController::class, 'open']);
            Route::post('secrets', [DecodeAnonymousController::class, 'generateSecret']);
            Route::post('secrets/{secret}/proposals', [DecodeAnonymousController::class, 'propose']);
            Route::post('close', [DecodeAnonymousController::class, 'close']);
            Route::post('export/rules', [DecodeAnonymousController::class, 'exportRules']);
            Route::post('export/results', [DecodeAnonymousController::class, 'exportResults']);
            Route::post('reset-complete', [DecodeAnonymousController::class, 'resetComplete']);
        });
        Route::post('reset-request', [DecodeAnonymousController::class, 'resetRequest'])->middleware('throttle:anim-reset');
    });
    // Lien signé de confirmation de l'adresse (D29), rappelé en JSON par la page /app/anim/decode/confirmer-email du site.
    Route::get('anim/decode-email-confirm/{slug}/{hash}', [DecodeAnonymousController::class, 'confirmEmail'])
        ->where(['slug' => '[a-z-]{1,60}', 'hash' => '[a-f0-9]{40}'])
        ->middleware('signed')
        ->name('anim.decode.email-confirm');

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
        // Animation — Décode le code (brief admin/modules/animation/brief-coffres-scelles.md).
        // Autorisation par PROPRIÉTÉ (DecodeWorkflow), jamais par poste. ⚠️ Routes ajoutées : route:cache en prod.
        Route::get('characters/{character}/decode-activities', [DecodeActivityController::class, 'index'])
            ->where('character', '[0-9]+');
        Route::middleware('throttle:6,1')->group(function () {
            Route::post('characters/{character}/decode-activities', [DecodeActivityController::class, 'store'])
                ->where('character', '[0-9]+');
        });
        Route::prefix('decode-activities/{activity}')->where(['activity' => '[0-9]+', 'secret' => '[0-9]+'])->group(function () {
            Route::post('secrets', [DecodeActivityController::class, 'generateSecret']);
            Route::post('secrets/{secret}/proposals', [DecodeActivityController::class, 'propose']);
            Route::post('reopen', [DecodeActivityController::class, 'reopen']);
            Route::post('close', [DecodeActivityController::class, 'close']);
            Route::delete('', [DecodeActivityController::class, 'destroy']);
            Route::get('export/rules', [DecodeActivityController::class, 'exportRules']);
            Route::get('export/results', [DecodeActivityController::class, 'exportResults']);
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

        // Correction du pseudo par l'admin (demande joueur en cas d'erreur à l'inscription).
        // ⚠️ Routes ajoutées : route:cache en prod.
        Route::middleware('role:admin')->group(function () {
            Route::patch('admin/characters/{character}/pseudo', [AdminCharacterController::class, 'updatePseudo'])
                ->where('character', '[0-9]+');
        });
    });

});
