<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\MandateAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('auth.login');
});

// Route::get('/dashboard', function () {
//    return view('dashboard');
// })->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('users', [DashboardController::class, 'users'])->name('users');
    Route::delete('users/{user}', [DashboardController::class, 'destroyUser'])->name('users.destroy');
    Route::patch('characters/{character}/validate', [DashboardController::class, 'toggleValidation'])->name('characters.validate');

    // Mandats (admin/content/brief-mandats.md, lot 1 ; arbitrages admin/echanges/mandats-lot1/).
    Route::prefix('mandates')->name('mandates.')->controller(MandateAdminController::class)->group(function () {
        Route::get('/', 'pending')->name('pending');
        Route::get('running', 'running')->name('running');
        Route::get('decisions', 'decisions')->name('decisions');
        Route::get('verification', 'verification')->name('verification');
        Route::get('history', 'history')->name('history');
        Route::post('batch-approve', 'batchApprove')->name('batch-approve');

        Route::prefix('provinces/{province}')->name('provinces.')->group(function () {
            Route::post('extend', 'extend')->name('extend');
            Route::post('handover', 'handover')->name('handover');
            Route::post('clear-offices', 'clearOffices')->name('clear-offices');
        });

        Route::prefix('council/{id}')->name('council.')->where(['id' => '[0-9]+'])->group(function () {
            Route::post('in-office', 'correctInOffice')->name('in-office');
            Route::post('office', 'setOffice')->name('office');
            Route::post('office/approve', 'approveOfficeRequest')->name('office.approve');
            Route::post('office/reject', 'rejectOfficeRequest')->name('office.reject');
        });

        Route::prefix('{level}/{id}')->where(['level' => 'mayor|council', 'id' => '[0-9]+'])->group(function () {
            Route::post('approve', 'approve')->name('approve');
            Route::post('reject', 'reject')->name('reject');
            Route::post('revoke', 'revoke')->name('revoke');
            Route::post('unrevoke', 'unrevoke')->name('unrevoke');
            Route::post('correct-start', 'correctStart')->name('correct-start');
            Route::post('correct-decision', 'correctDecision')->name('correct-decision');
            Route::post('verify', 'verify')->name('verify');
        });
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
