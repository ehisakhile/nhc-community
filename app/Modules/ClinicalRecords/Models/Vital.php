<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;

class Vital extends Model
{
    use ClinicalRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['temperature_c' => 'float', 'weight_kg' => 'float', 'height_cm' => 'float'];
    }
}
