<?php

namespace App\Modules\ClinicalRecords;

use App\Modules\Module;

/** The central health record: visits, vitals, notes, diagnoses, allergies, prescriptions, vaccinations. */
class ClinicalRecordsModule extends Module
{
    public function key(): string
    {
        return 'clinical_records';
    }

    public function features(): array
    {
        return [
            [
                'key' => 'clinical_records', 'name' => 'Clinical records', 'category' => 'Clinical', 'default_on' => true,
                'description' => 'Visits, vitals, consultation notes, diagnoses, allergies and vaccinations, kept for life. Shared with other hospitals only with the patient\'s approval.',
            ],
            [
                'key' => 'prescriptions', 'name' => 'Prescriptions', 'category' => 'Clinical', 'default_on' => true, 'depends_on' => ['clinical_records'],
                'description' => 'Doctors prescribe in the visit; your pharmacy dispenses, and the patient sees it in the Hospify app.',
            ],
        ];
    }
}
