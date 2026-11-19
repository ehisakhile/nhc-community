<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;

/** From a clinician, or added by the patient (shown as theirs until a clinician confirms it). */
class Allergy extends Model
{
    use ClinicalRecord;

    public const SEVERITIES = ['mild', 'moderate', 'severe', 'unknown'];

    protected $guarded = ['id'];

    protected array $mutable = ['status', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}
