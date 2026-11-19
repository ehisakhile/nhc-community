<?php

use App\Modules\Staff\Http\FacilityController;
use App\Modules\Staff\Http\InviteController;
use App\Modules\Staff\Http\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:staff'])->prefix('staff')->group(function () {
    Route::post('facilities', [FacilityController::class, 'store'])->middleware('throttle:10,1');
    Route::get('me', [ProfileController::class, 'show']);
    Route::post('facilities/{facility}/invites', [InviteController::class, 'store']);
});
