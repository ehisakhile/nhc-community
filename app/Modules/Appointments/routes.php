<?php

use App\Modules\Appointments\Http\PatientAppointmentController;
use App\Modules\Appointments\Http\StaffAppointmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:patient'])->prefix('patient/appointments')->controller(PatientAppointmentController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store')->middleware('throttle:20,60');
    Route::post('{id}/cancel', 'cancel');
});

Route::middleware(['auth:sanctum', 'abilities:patient'])->get('patient/facilities/{facility}/slots', [PatientAppointmentController::class, 'slots']);

Route::middleware(['auth:sanctum', 'abilities:staff', 'feature:appointments'])
    ->prefix('staff/facilities/{facility}/appointments')
    ->controller(StaffAppointmentController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::post('{id}/confirm', 'confirm');
        Route::post('{id}/decline', 'decline');
        Route::post('{id}/{action}', 'move')->whereIn('action', ['checked_in', 'completed', 'missed']);
    });
