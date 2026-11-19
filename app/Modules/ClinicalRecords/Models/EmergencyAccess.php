<?php

namespace App\Modules\ClinicalRecords\Models;

use App\Exceptions\ImmutableRecord;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Break-glass: the summary from every hospital, for a day. Logged, and the patient is told. */
class EmergencyAccess extends Model
{
    use CentralConnection, HasUlids;

    public const HOURS = 24;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'patient_told_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecord('Emergency access is kept as a record.'));
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
