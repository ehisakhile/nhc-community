<?php

namespace App\Modules\ClinicalRecords\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Encrypted at rest. Corrected by adding a note that amends it, never by editing. */
class ClinicalNote extends Model
{
    use ClinicalRecord;

    public const KINDS = ['consultation', 'progress', 'discharge_summary'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function amends(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amends_id');
    }
}
