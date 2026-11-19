<?php

namespace App\Modules\ClinicalRecords\Models;

use App\Exceptions\ImmutableRecord;
use App\Models\Concerns\BelongsToFacility;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A row in the central health record. Locked to the current hospital by
 * default (a forgotten filter returns nothing); only ClinicalAccess reads
 * across hospitals. Never rewritten or deleted: corrections are added, and a
 * wrong row is marked "entered in error". A model lists the few fields that may
 * change (a status, say) in $mutable.
 */
trait ClinicalRecord
{
    use BelongsToFacility, CentralConnection, HasUlids;

    public static function bootClinicalRecord(): void
    {
        static::updating(function (self $row) {
            $allowed = [...$row->mutable ?? [], 'voided_at', 'voided_by', 'void_reason', 'updated_at'];
            if (array_diff(array_keys($row->getDirty()), $allowed)) {
                throw new ImmutableRecord("A clinical record can't be rewritten. Add to it instead.");
            }
        });

        static::deleting(function () {
            throw new ImmutableRecord('Clinical records are kept for life.');
        });
    }

    public function initializeClinicalRecord(): void
    {
        $this->mergeCasts(['recorded_at' => 'datetime', 'voided_at' => 'datetime']);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
