<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;

/** Blood group or genotype. The latest one that isn't voided counts. */
class PatientFact extends Model
{
    use ClinicalRecord;

    public const KINDS = [
        'blood_group' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
        'genotype' => ['AA', 'AS', 'AC', 'SS', 'SC', 'CC'],
    ];

    protected $guarded = ['id'];
}
