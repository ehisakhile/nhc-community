<?php

use App\Modules\Appointments\AppointmentsModule;
use App\Modules\Auth\AuthModule;
use App\Modules\ClinicalRecords\ClinicalRecordsModule;
use App\Modules\Enquiries\EnquiriesModule;
use App\Modules\FeatureHub\FeatureHubModule;
use App\Modules\HospifyLink\HospifyLinkModule;
use App\Modules\Messages\MessagesModule;
use App\Modules\Patients\PatientsModule;
use App\Modules\RecordSharing\RecordSharingModule;
use App\Modules\Scheduling\SchedulingModule;
use App\Modules\Staff\StaffModule;

// NHC's modules, in the order they show in the Feature hub. Each one lives in
// app/Modules/<Name> with its own routes, controllers and the features it gives.
return [
    AuthModule::class,
    StaffModule::class,
    PatientsModule::class,
    ClinicalRecordsModule::class,
    SchedulingModule::class,
    AppointmentsModule::class,
    MessagesModule::class,
    RecordSharingModule::class,
    HospifyLinkModule::class,
    FeatureHubModule::class,
    EnquiriesModule::class,
];
