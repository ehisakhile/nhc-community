<?php

use App\Modules\Patients\Http\PatientController;
use App\Modules\Patients\Http\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:staff'])->prefix('staff/facilities/{facility}/patients')->group(function () {
    Route::get('/', [PatientController::class, 'index']);
    // New to NHC: details, one government ID, a code to their phone.
    Route::post('/', [RegistrationController::class, 'start']);
    Route::post('verify', [RegistrationController::class, 'verify']);
    // Already on NHC at another hospital: find, then a code to their phone.
    Route::post('find', [PatientController::class, 'find']);
    Route::post('join', [PatientController::class, 'join']);
    Route::post('join/verify', [PatientController::class, 'confirmJoin']);
    Route::get('{nhcId}', [PatientController::class, 'show'])->where('nhcId', 'NHC-[0-9-]+');
});
