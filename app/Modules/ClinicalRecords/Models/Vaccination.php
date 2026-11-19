<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;

class Vaccination extends Model
{
    use ClinicalRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['given_on' => 'date'];
    }
}
