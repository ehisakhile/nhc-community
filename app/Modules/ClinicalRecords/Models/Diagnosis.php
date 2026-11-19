<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;

class Diagnosis extends Model
{
    use ClinicalRecord;

    protected $guarded = ['id'];

    protected array $mutable = ['status', 'resolved_at'];

    protected function casts(): array
    {
        return ['chronic' => 'boolean', 'resolved_at' => 'datetime'];
    }
}
