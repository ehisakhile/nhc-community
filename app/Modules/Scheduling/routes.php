<?php

use App\Modules\Scheduling\Http\StaffScheduleController;
use Illuminate\Support\Facades\Route;

// The facility's booking hours: weekly windows (facility wide or per care service), settings and closed dates.
Route::middleware(['auth:sanctum', 'abilities:staff'])->prefix('staff/facilities/{facility}/schedule')
    ->controller(StaffScheduleController::class)->group(function () {
        Route::get('/', 'show');
        Route::get('slots', 'slots');
        Route::put('windows', 'windows');
        Route::put('settings', 'settings');
        Route::post('closures', 'addClosure');
        Route::delete('closures/{id}', 'removeClosure');
    });
