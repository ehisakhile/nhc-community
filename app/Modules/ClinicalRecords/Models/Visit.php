<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    use ClinicalRecord;

    protected $guarded = ['id'];

    protected array $mutable = ['status', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class);
    }
}
