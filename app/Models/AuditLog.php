<?php

namespace App\Models;

use App\Exceptions\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Add-only history of access to patient data. */
class AuditLog extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_type', 'actor_id', 'facility_id', 'patient_id', 'action',
        'target_type', 'target_id', 'reason', 'ip_address', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $block = function () {
            throw new ImmutableRecord('The audit log can only be added to.');
        };

        static::updating($block);
        static::deleting($block);
    }
}
