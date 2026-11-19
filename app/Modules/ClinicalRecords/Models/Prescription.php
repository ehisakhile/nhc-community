<?php

namespace App\Modules\ClinicalRecords\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    use ClinicalRecord;

    protected $guarded = ['id'];

    protected array $mutable = ['status', 'stopped_at', 'stop_reason', 'dispensed_by', 'dispensed_at'];

    protected function casts(): array
    {
        return ['stopped_at' => 'datetime', 'dispensed_at' => 'datetime'];
    }

    public function dispenser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }
}
