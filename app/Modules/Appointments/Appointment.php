<?php

namespace App\Modules\Appointments;

use App\Models\Facility;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Appointment extends Model
{
    use CentralConnection, HasUlids;

    public const OPEN = ['requested', 'confirmed', 'checked_in'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'preferred_date' => 'date', 'scheduled_at' => 'datetime', 'slot_at' => 'datetime', 'history' => 'array', 'reminded' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /** $flag: why staff booked it against the hours ("Booked outside hours", "Over capacity"). */
    public function record(string $status, ?string $by, ?string $note = null, ?string $flag = null): void
    {
        $this->status = $status;
        $this->history = [...($this->history ?? []), ['status' => $status, 'at' => now()->toIso8601String(), 'by' => $by, 'note' => $note,
            ...($flag ? ['flag' => $flag] : [])]];
        $this->save();
    }
}
