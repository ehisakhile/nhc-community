<?php

use App\Modules\Enquiries\Http\FacilityEnquiryController;
use App\Modules\Enquiries\Http\FacilityRegistrationController;
use Illuminate\Support\Facades\Route;

// Public: the website's "Join NHC" form. The site posts from its server, so
// every visitor shares one address: the limit is per site, not per person.
Route::post('facility-enquiries', [FacilityEnquiryController::class, 'store'])->middleware('throttle:30,60');

// Public: the website's "Register your facility". The emailed code limits each
// address to 5 an hour; this caps the site as a whole.
Route::prefix('facilities/register')->middleware('throttle:60,60')->controller(FacilityRegistrationController::class)->group(function () {
    Route::post('start', 'start');
    Route::post('verify', 'verify');
});
