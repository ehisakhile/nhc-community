<?php

namespace App\Modules\RecordSharing;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class RecordRequest extends Model
{
    use CentralConnection, HasUlids;

    public const OPEN = ['awaiting_patient', 'awaiting_hospital'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'record_types' => 'array',
            'granted_record_types' => 'array',
            'patient_decided_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'requesting_facility_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'holding_facility_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Approved view access runs out; a copy doesn't. */
    public function effectiveStatus(): string
    {
        return $this->status === 'approved' && $this->expires_at?->isPast() ? 'expired' : $this->status;
    }

    public function isActiveGrant(): bool
    {
        return $this->effectiveStatus() === 'approved';
    }
}
