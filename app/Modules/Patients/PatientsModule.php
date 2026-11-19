<?php

namespace App\Modules\Patients;

use App\Modules\Module;

/** Registering new patients, onboarding ones already on NHC, and the hospital's patient list. */
class PatientsModule extends Module
{
    public function key(): string
    {
        return 'patients';
    }

    public function features(): array
    {
        return [[
            'key' => 'patient_registration', 'name' => 'Patient registration', 'category' => 'Front desk', 'core' => true,
            'description' => 'Register new patients with an ID and a code to their phone, and onboard patients already on NHC without registering them again.',
        ]];
    }
}
