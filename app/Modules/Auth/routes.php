<?php

use App\Modules\Auth\Http\HospifySignInController;
use App\Modules\Auth\Http\HospifyStaffSignInController;
use App\Modules\Auth\Http\StaffSignInController;
use Illuminate\Support\Facades\Route;

// Per IP. Staff on the NHC web app all arrive from its server's IP, so this is
// generous; the per-email and per-phone code limits do the real work.
Route::prefix('auth')->middleware('throttle:120,1')->group(function () {
    // Staff (NHC web and desktop apps): email code, or password (demo accounts).
    Route::post('staff/code', [StaffSignInController::class, 'start']);
    Route::post('staff/verify', [StaffSignInController::class, 'verify']);
    Route::post('staff/password', [StaffSignInController::class, 'password']);

    Route::post('staff/hospify', [HospifyStaffSignInController::class, 'exchange']);

    // Patients: only through the Hospify Citizen app, with a pass Hospify signed.
    Route::post('hospify', [HospifySignInController::class, 'exchange']);
});

Route::post('staff/sign-out', [StaffSignInController::class, 'signOut'])->middleware(['auth:sanctum', 'abilities:staff']);
Route::post('patient/sign-out', [HospifySignInController::class, 'signOut'])->middleware(['auth:sanctum', 'abilities:patient']);
